<?php

namespace Tests\Feature;

use App\Jobs\SentimentJob;
use App\Models\Agency;
use App\Models\Client;
use App\Models\Message;
use App\Models\Project;
use App\Models\ProjectEvent;
use App\Models\User;
use App\Services\SentimentService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

class SentimentTest extends TestCase
{
    use RefreshDatabase;

    private function makeAgency(string $slug = 'sent-agency'): Agency
    {
        return Agency::create([
            'name'         => "Agency {$slug}",
            'domain_slug'  => $slug,
            'brand_colors' => ['primary' => '#802AEE'],
        ]);
    }

    private function makeUser(Agency $agency, string $role = 'admin'): User
    {
        static $n = 0;
        $n++;

        return User::create([
            'agency_id' => $agency->id,
            'role'      => $role,
            'name'      => ucfirst($role) . " {$n}",
            'email'     => "{$role}{$n}@sent-test.com",
            'password'  => Hash::make('password'),
        ]);
    }

    private function makeClientWithUser(Agency $agency): array
    {
        $contact = $this->makeUser($agency, 'client');

        $client = Client::create([
            'agency_id'       => $agency->id,
            'company_name'    => 'Sentiment Client',
            'contact_user_id' => $contact->id,
        ]);

        return [$client, $contact];
    }

    private function makeProject(Agency $agency, Client $client): Project
    {
        return Project::create([
            'agency_id'  => $agency->id,
            'client_id'  => $client->id,
            'name'       => 'Sentiment Project',
            'type'       => 'web_design',
            'status'     => 'active',
            'start_date' => '2026-07-01',
            'end_date'   => '2026-09-30',
        ]);
    }

    private function fakeSentiment(float $score): void
    {
        Http::fake([
            'localhost:8001/sentiment' => Http::response([
                'success' => true,
                'data'    => [
                    'score' => $score,
                    'label' => $score > 0.3 ? 'positive' : ($score > -0.3 ? 'neutral' : 'negative'),
                ],
            ], 200),
        ]);
    }

    public function test_message_creates_sentiment_job(): void
    {
        Queue::fake();

        $agency               = $this->makeAgency();
        [$client, $clientUser] = $this->makeClientWithUser($agency);
        $project              = $this->makeProject($agency, $client);

        $response = $this->actingAs($clientUser)
            ->postJson("/api/v1/projects/{$project->id}/messages", [
                'body' => 'I am unhappy with the latest deliverable.',
            ]);

        $response->assertStatus(201)
            ->assertJsonPath('success', true);

        $this->assertDatabaseHas('messages', [
            'project_id' => $project->id,
            'sender_id'  => $clientUser->id,
            'body'       => 'I am unhappy with the latest deliverable.',
        ]);

        Queue::assertPushed(SentimentJob::class, function (SentimentJob $job) {
            return $job->queue === 'ai';
        });
    }

    public function test_three_consecutive_negatives_logs_event(): void
    {
        $agency               = $this->makeAgency('sent-neg');
        [$client, $clientUser] = $this->makeClientWithUser($agency);
        $project              = $this->makeProject($agency, $client);

        Message::create([
            'agency_id'       => $agency->id,
            'project_id'      => $project->id,
            'sender_id'       => $clientUser->id,
            'body'            => 'Bad update 1',
            'sentiment_score' => -0.5,
            'created_at'      => now()->subMinutes(3),
        ]);
        Message::create([
            'agency_id'       => $agency->id,
            'project_id'      => $project->id,
            'sender_id'       => $clientUser->id,
            'body'            => 'Bad update 2',
            'sentiment_score' => -0.6,
            'created_at'      => now()->subMinutes(2),
        ]);

        $pending = Message::create([
            'agency_id'  => $agency->id,
            'project_id' => $project->id,
            'sender_id'  => $clientUser->id,
            'body'       => 'Bad update 3',
        ]);

        $this->fakeSentiment(-0.7);

        $service = app(SentimentService::class);
        $service->analyzeMessage($pending);

        $this->assertDatabaseHas('project_events', [
            'agency_id'  => $agency->id,
            'project_id' => $project->id,
            'event_type' => 'client_sentiment_declining',
        ]);

        $event = ProjectEvent::where('event_type', 'client_sentiment_declining')->first();
        $this->assertEquals($client->id, $event->metadata['client_id']);
        $this->assertLessThan(-0.3, $event->metadata['avg_score']);
    }

    public function test_client_sentiment_summary_returns_at_risk(): void
    {
        $agency               = $this->makeAgency('sent-risk');
        $admin                = $this->makeUser($agency, 'admin');
        [$client, $clientUser] = $this->makeClientWithUser($agency);
        $project              = $this->makeProject($agency, $client);

        foreach ([-0.5, -0.6, -0.7] as $i => $score) {
            Message::create([
                'agency_id'       => $agency->id,
                'project_id'      => $project->id,
                'sender_id'       => $clientUser->id,
                'body'            => "Negative message {$i}",
                'sentiment_score' => $score,
                'created_at'      => now()->subMinutes(3 - $i),
            ]);
        }

        $response = $this->actingAs($admin)
            ->getJson('/api/v1/clients/sentiment');

        $response->assertOk()
            ->assertJsonPath('success', true);

        $clients = collect($response->json('data'));
        $entry   = $clients->firstWhere('client_id', $client->id);

        $this->assertNotNull($entry);
        $this->assertTrue($entry['at_risk']);
        $this->assertEquals('negative', $entry['label']);
    }
}
