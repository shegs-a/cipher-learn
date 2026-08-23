<?php

declare(strict_types=1);

namespace App\Models\Concerns;

use App\Models\Tenant;
use App\Support\Tenancy;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Scope;

/**
 * Applies tenant isolation to a model: a global scope constrains every query to
 * the current tenant, and new records are stamped with it automatically. This is
 * the enforcement the brief asks for — isolation that a forgotten `where` clause
 * cannot bypass, rather than a convention each developer must remember.
 *
 * When no tenant is in context (a system/console operation) the scope is not
 * applied; that path is explicit via Tenancy::runFor().
 */
trait BelongsToTenant
{
    public static function bootBelongsToTenant(): void
    {
        $column = self::tenantColumn();

        static::addGlobalScope(new class($column) implements Scope
        {
            public function __construct(private readonly string $column) {}

            public function apply(Builder $builder, Model $model): void
            {
                $tenancy = app(Tenancy::class);

                if ($tenancy->has()) {
                    $builder->where($model->getTable().'.'.$this->column, $tenancy->id());
                }
            }
        });

        static::creating(function (Model $model) use ($column): void {
            $tenancy = app(Tenancy::class);

            if ($tenancy->has() && $model->getAttribute($column) === null) {
                $model->setAttribute($column, $tenancy->id());
            }
        });
    }

    public static function tenantColumn(): string
    {
        return 'tenant_id';
    }

    /**
     * @return BelongsTo<Tenant, $this>
     */
    public function tenant(): BelongsTo
    {
        return $this->belongsTo(Tenant::class);
    }
}
