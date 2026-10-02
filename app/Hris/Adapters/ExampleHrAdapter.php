<?php

declare(strict_types=1);

namespace App\Hris\Adapters;

use App\Enums\EmployeeStatus;
use App\Hris\Contracts\HrisEmployeeSource;
use App\Hris\Contracts\HrisLeaveSource;
use App\Hris\Contracts\HrisWriteback;
use App\Hris\Data\EmployeeData;
use App\Hris\Data\TrainingCompletionData;
use App\Hris\Exceptions\HrisConnectionException;
use App\Hris\Exceptions\HrisUnsupportedOperation;
use Carbon\CarbonImmutable;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Support\Facades\Http;
use Throwable;

/**
 * ExampleHR as a source of employees — one concrete implementation of the
 * {@see HrisEmployeeSource} / {@see HrisWriteback} port.
 *
 * FICTIONAL VENDOR. "ExampleHR" and `https://docs.example.com` are placeholders;
 * they do not represent any real company or product (see the README disclaimer).
 * The adapter is written as if against a published API spec: where that
 * hypothetical spec leaves something unspecified, the code makes a conservative,
 * clearly-marked assumption (search for "ASSUMPTION") rather than inventing a
 * detail that would look authoritative and be wrong.
 *
 * Credentials are per-tenant and arrive via `tenants.settings` — never config,
 * never committed.
 *
 * STATUS: structurally complete but exercised only via Http::fake(). Treat the
 * field mapping as a starting point to confirm against a real response before any
 * real integration.
 */
final class ExampleHrAdapter implements HrisEmployeeSource, HrisLeaveSource, HrisWriteback
{
    /** @param array<string, mixed> $settings Per-tenant config from tenants.settings. */
    public function __construct(private readonly array $settings = []) {}

    public function name(): string
    {
        return 'example';
    }

    /**
     * ExampleHR's public docs publish no leave endpoint, so this adapter honestly
     * reports the capability as absent (same modelling as write-back). The leave
     * sync skips tenants on this adapter rather than faking an empty calendar.
     */
    public function supportsLeave(): bool
    {
        return false;
    }

    /**
     * @return iterable<int, never>
     */
    public function fetchLeave(CarbonImmutable $from, CarbonImmutable $to): iterable
    {
        throw HrisUnsupportedOperation::for($this->name(), 'fetchLeave');
    }

    /**
     * Page through the employee directory, yielding neutral DTOs.
     *
     * A generator so a large org streams rather than materialising in memory —
     * the sync consumes it one record at a time.
     *
     * @return iterable<int, EmployeeData>
     */
    public function fetchEmployees(): iterable
    {
        $page = 1;
        $pageSize = (int) config('hris.example.page_size', 100);

        do {
            $payload = $this->get('/v1/employees', [
                'page' => $page,
                'per_page' => $pageSize,
            ]);

            // ASSUMPTION: a `data` collection with pagination metadata, the
            // conventional Laravel-style envelope the public docs present.
            $records = $payload['data'] ?? [];

            foreach ($records as $record) {
                if (is_array($record)) {
                    yield $this->toEmployeeData($record);
                }
            }

            $page++;

            // Stop when a short page comes back — works whether or not the API
            // returns a total, so we never loop forever on an unexpected shape.
        } while (count($records) === $pageSize && $pageSize > 0);
    }

    public function fetchEmployee(string $externalId): ?EmployeeData
    {
        $payload = $this->get('/v1/employees/'.urlencode($externalId));

        $record = $payload['data'] ?? null;

        return is_array($record) ? $this->toEmployeeData($record) : null;
    }

    /**
     * ExampleHR publishes no training-completion endpoint.
     *
     * This is the concrete reason the port models write-back as an optional
     * capability. Throwing here (rather than silently succeeding) is what stops
     * Sprint 6's outbox from recording a completion that reached nothing.
     *
     * @throws HrisUnsupportedOperation always.
     */
    public function pushTrainingCompletion(TrainingCompletionData $completion): void
    {
        throw HrisUnsupportedOperation::for($this->name(), 'pushTrainingCompletion');
    }

    public function supportsWriteback(): bool
    {
        return false;
    }

    /**
     * Map an ExampleHR employee record into our neutral DTO.
     *
     * Every vendor-shaped assumption in the integration lives in this one method
     * on purpose — when the real response is confirmed, this is the only place
     * that changes.
     *
     * @param  array<string, mixed>  $record
     */
    private function toEmployeeData(array $record): EmployeeData
    {
        return new EmployeeData(
            externalId: (string) ($record['employee_id'] ?? $record['id'] ?? ''),
            firstName: (string) ($record['first_name'] ?? ''),
            lastName: (string) ($record['last_name'] ?? ''),
            email: $this->nullableString($record['email'] ?? $record['work_email'] ?? null),
            // ASSUMPTION: department/job title may arrive either flat or nested
            // under an object; accept both rather than guessing exclusively.
            department: $this->nullableString(
                $record['department'] ?? ($record['department_name'] ?? null)
            ),
            jobTitle: $this->nullableString(
                $record['job_title'] ?? ($record['designation'] ?? null)
            ),
            location: $this->nullableString($record['location'] ?? null),
            managerExternalId: $this->nullableString(
                $record['manager_id'] ?? ($record['line_manager_id'] ?? null)
            ),
            status: $this->toStatus($record['status'] ?? null),
        );
    }

    /**
     * Normalise a vendor status into our two-state domain enum.
     *
     * Anything not clearly indicating a leaver is treated as active — a false
     * "exited" would wrongly withdraw somebody's training, which is worse than
     * briefly keeping a leaver active until the next sync.
     */
    private function toStatus(mixed $status): EmployeeStatus
    {
        if (! is_string($status)) {
            return EmployeeStatus::Active;
        }

        $exited = ['exited', 'terminated', 'inactive', 'resigned', 'offboarded'];

        return in_array(strtolower(trim($status)), $exited, true)
            ? EmployeeStatus::Exited
            : EmployeeStatus::Active;
    }

    private function nullableString(mixed $value): ?string
    {
        if (is_array($value)) {
            // Nested object (e.g. {"name": "Finance"}) — take the obvious label.
            $value = $value['name'] ?? $value['title'] ?? $value['id'] ?? null;
        }

        if ($value === null) {
            return null;
        }

        $value = trim((string) $value);

        return $value === '' ? null : $value;
    }

    /**
     * Issue a GET and return the decoded body.
     *
     * Wraps every failure in HrisConnectionException so no Guzzle or Laravel
     * HTTP type escapes the port — callers depend on our exceptions only.
     *
     * @param  array<string, mixed>  $query
     * @return array<string, mixed>
     */
    private function get(string $path, array $query = []): array
    {
        try {
            $response = $this->client()->get($path, $query);

            if ($response->failed()) {
                throw HrisConnectionException::for(
                    $this->name(),
                    "GET {$path} returned HTTP {$response->status()}",
                );
            }

            $decoded = $response->json();

            return is_array($decoded) ? $decoded : [];
        } catch (HrisConnectionException $e) {
            throw $e;
        } catch (Throwable $e) {
            throw HrisConnectionException::for($this->name(), $e->getMessage(), $e);
        }
    }

    private function client(): PendingRequest
    {
        $token = $this->settings['api_token'] ?? null;
        $baseUrl = $this->settings['base_url'] ?? config('hris.example.base_url');

        return Http::baseUrl((string) $baseUrl)
            ->timeout((int) config('hris.example.timeout', 15))
            ->retry(
                (int) config('hris.example.retry_times', 2),
                (int) config('hris.example.retry_sleep_ms', 200),
                throw: false,
            )
            ->acceptJson()
            ->when(is_string($token) && $token !== '', fn (PendingRequest $r) => $r->withToken($token));
    }
}
