<?php

namespace Database\Factories;

use App\Models\Condominium;
use App\Models\Ticket;
use App\Models\WebhookDelivery;
use App\Models\WebhookDeliveryStatus;
use App\Models\WebhookEvent;
use Database\Factories\Concerns\InheritsCondominium;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Database\Eloquent\Model;

/**
 * @extends Factory<WebhookDelivery>
 */
class WebhookDeliveryFactory extends Factory
{
    use InheritsCondominium;

    /**
     * Define the model's default state: a pending ticket.status_changed delivery.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'condominium_id' => fn (array $attributes) => is_string($attributes['subject_type']) && is_a($attributes['subject_type'], Model::class, true)
                ? ($this->parentAttribute($attributes['subject_id'], $attributes['subject_type'], 'condominium_id') ?? Condominium::factory())
                : Condominium::factory(),
            'webhook_event_id' => WebhookEvent::idFor(WebhookEvent::TICKET_STATUS_CHANGED),
            'webhook_delivery_status_id' => WebhookDeliveryStatus::idFor(WebhookDeliveryStatus::PENDENTE),
            'subject_type' => Ticket::class,
            'subject_id' => fn (array $attributes) => Ticket::factory()->state(['condominium_id' => $attributes['condominium_id']]),
            'resident_phone' => fake()->numerify('+55419########'),
            'url' => fake()->url(),
            'payload' => ['event' => WebhookEvent::TICKET_STATUS_CHANGED],
            'attempts' => 0,
            'last_response_code' => null,
            'last_error' => null,
            'delivered_at' => null,
            'failed_at' => null,
        ];
    }

    public function enviado(): static
    {
        return $this->state(fn (array $attributes) => [
            'webhook_delivery_status_id' => WebhookDeliveryStatus::idFor(WebhookDeliveryStatus::ENVIADO),
            'attempts' => 1,
            'last_response_code' => 200,
            'delivered_at' => now(),
        ]);
    }

    /**
     * Delivery that failed after the last retry.
     */
    public function falhou(): static
    {
        return $this->state(fn (array $attributes) => [
            'webhook_delivery_status_id' => WebhookDeliveryStatus::idFor(WebhookDeliveryStatus::FALHOU),
            'attempts' => config('condo.webhooks.tries'),
            'last_response_code' => 500,
            'last_error' => 'Server Error',
            'failed_at' => now(),
        ]);
    }
}
