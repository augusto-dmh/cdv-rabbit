<?php

namespace Database\Factories;

use App\Enums\WebhookDeliveryStatus;
use App\Models\Repository;
use App\Models\WebhookDelivery;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<WebhookDelivery>
 */
class WebhookDeliveryFactory extends Factory
{
    public function definition(): array
    {
        return [
            'scm_delivery_id' => fake()->uuid(),
            'scm_provider' => 'bitbucket_cloud',
            'repository_id' => null,
            'event_type' => 'pullrequest:created',
            'status' => WebhookDeliveryStatus::Received,
            'processed_at' => null,
            'created_at' => now(),
        ];
    }

    public function forRepository(Repository $repository): static
    {
        return $this->state(['repository_id' => $repository->id]);
    }
}
