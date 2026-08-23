<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Enums\EmployeeStatus;
use App\Models\Employee;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Employee>
 */
class EmployeeFactory extends Factory
{
    protected $model = Employee::class;

    public function definition(): array
    {
        // West & East African names, spread across the four hub cities the
        // product targets. Kept here so both the seeder and the Sprint 2 mock
        // adapter draw from the same realistic pool.
        $firstNames = ['Chidi', 'Ngozi', 'Emeka', 'Amara', 'Kwame', 'Ama', 'Kofi', 'Abena', 'Wanjiru', 'Otieno', 'Njeri', 'Mwangi', 'Nakato', 'Okello', 'Ssentongo', 'Adaeze', 'Yaw', 'Zainab', 'Tunde', 'Fatima'];
        $lastNames = ['Okafor', 'Adeyemi', 'Mensah', 'Osei', 'Kamau', 'Achieng', 'Owusu', 'Balogun', 'Mutiso', 'Wekesa', 'Nabirye', 'Kato', 'Eze', 'Asante', 'Njoroge', 'Abubakar'];

        return [
            'external_id' => 'EMP-'.fake()->unique()->numberBetween(10000, 99999),
            'first_name' => fake()->randomElement($firstNames),
            'last_name' => fake()->randomElement($lastNames),
            'email' => fake()->unique()->safeEmail(),
            'department' => fake()->randomElement(['Sales', 'Operations', 'Finance', 'People', 'Engineering']),
            'job_title' => fake()->jobTitle(),
            'location' => fake()->randomElement(['Lagos', 'Accra', 'Nairobi', 'Kampala']),
            'status' => EmployeeStatus::Active,
        ];
    }

    public function exited(): static
    {
        return $this->state(fn () => ['status' => EmployeeStatus::Exited]);
    }
}
