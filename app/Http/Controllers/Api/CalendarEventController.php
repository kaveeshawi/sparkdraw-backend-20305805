<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Traits\ApiResponse;
use App\Models\CalendarEvent;
use App\Models\Milestone;
use App\Models\Project;
use App\Models\Task;
use Carbon\Carbon;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class CalendarEventController extends Controller
{
    use ApiResponse;

    // GET /api/v1/calendar/events?from=&to=
    public function index(Request $request): JsonResponse
    {
        $user = $request->user();
        $from = Carbon::parse($request->query('from', now()->startOfMonth()->toDateString()))->startOfDay();
        $to = Carbon::parse($request->query('to', now()->endOfMonth()->toDateString()))->endOfDay();

        if ($to->lt($from)) {
            return $this->error('Invalid date range.', [], 422);
        }

        $events = CalendarEvent::query()
            ->with(['creator:id,name', 'project:id,name,color'])
            ->where(function ($q) use ($from, $to) {
                $q->whereBetween('starts_at', [$from, $to])
                    ->orWhereBetween('ends_at', [$from, $to])
                    ->orWhere(function ($inner) use ($from, $to) {
                        $inner->where('starts_at', '<=', $from)->where('ends_at', '>=', $to);
                    });
            })
            ->orderBy('starts_at')
            ->get()
            ->map(fn (CalendarEvent $event) => $this->eventPayload($event));

        // Derived items from tasks / milestones on visible projects
        $visibleProjectIds = Project::query()->visibleTo($user)->pluck('id');

        $taskEvents = Task::query()
            ->with('project:id,name,color')
            ->whereIn('project_id', $visibleProjectIds)
            ->whereNotNull('deadline')
            ->whereBetween('deadline', [$from->toDateString(), $to->toDateString()])
            ->get()
            ->map(function (Task $task) {
                $deadline = Carbon::parse($task->deadline)->setTime(9, 0);
                return [
                    'id' => 'task-'.$task->id,
                    'source' => 'task',
                    'editable' => false,
                    'type' => 'tasks',
                    'title' => $task->title,
                    'subtitle' => $task->project?->name,
                    'description' => null,
                    'tone' => 'peach',
                    'all_day' => true,
                    'starts_at' => $deadline->toIso8601String(),
                    'ends_at' => $deadline->copy()->addHour()->toIso8601String(),
                    'project_id' => $task->project_id,
                    'created_by' => null,
                    'creator_name' => null,
                ];
            });

        $milestoneEvents = Milestone::query()
            ->with('project:id,name,color')
            ->whereIn('project_id', $visibleProjectIds)
            ->whereNotNull('due_date')
            ->whereBetween('due_date', [$from->toDateString(), $to->toDateString()])
            ->get()
            ->map(function (Milestone $milestone) {
                $due = Carbon::parse($milestone->due_date)->setTime(10, 0);
                return [
                    'id' => 'milestone-'.$milestone->id,
                    'source' => 'milestone',
                    'editable' => false,
                    'type' => 'milestones',
                    'title' => $milestone->title,
                    'subtitle' => $milestone->project?->name,
                    'description' => null,
                    'tone' => 'purple',
                    'all_day' => true,
                    'starts_at' => $due->toIso8601String(),
                    'ends_at' => $due->copy()->addHour()->toIso8601String(),
                    'project_id' => $milestone->project_id,
                    'created_by' => null,
                    'creator_name' => null,
                ];
            });

        $merged = $events
            ->concat($taskEvents)
            ->concat($milestoneEvents)
            ->sortBy('starts_at')
            ->values();

        return $this->success($merged);
    }

    // POST /api/v1/calendar/events
    public function store(Request $request): JsonResponse
    {
        $user = $request->user();
        $validated = $this->validateEvent($request);

        if (!empty($validated['project_id'])) {
            $project = Project::query()->visibleTo($user)->find($validated['project_id']);
            if (!$project) {
                return $this->error('Project not found.', [], 422);
            }
        }

        $type = $validated['type'];
        $event = CalendarEvent::create([
            'agency_id'   => $user->agency_id,
            'created_by'  => $user->id,
            'project_id'  => $validated['project_id'] ?? null,
            'title'       => $validated['title'],
            'subtitle'    => $validated['subtitle'] ?? null,
            'description' => $validated['description'] ?? null,
            'type'        => $type,
            'tone'        => $validated['tone'] ?? CalendarEvent::defaultTone($type),
            'starts_at'   => $validated['starts_at'],
            'ends_at'     => $validated['ends_at'],
            'all_day'     => (bool) ($validated['all_day'] ?? false),
        ]);

        $event->load(['creator:id,name', 'project:id,name,color']);

        return $this->created($this->eventPayload($event), 'Event created.');
    }

    // PUT /api/v1/calendar/events/{calendarEvent}
    public function update(Request $request, CalendarEvent $calendarEvent): JsonResponse
    {
        $user = $request->user();
        if (!$this->canEdit($user, $calendarEvent)) {
            return $this->error('You cannot edit this event.', [], 403);
        }

        $validated = $this->validateEvent($request, partial: true);

        if (array_key_exists('project_id', $validated) && $validated['project_id']) {
            $project = Project::query()->visibleTo($user)->find($validated['project_id']);
            if (!$project) {
                return $this->error('Project not found.', [], 422);
            }
        }

        if (isset($validated['type']) && empty($validated['tone'])) {
            $validated['tone'] = CalendarEvent::defaultTone($validated['type']);
        }

        $calendarEvent->update($validated);
        $calendarEvent->load(['creator:id,name', 'project:id,name,color']);

        return $this->success($this->eventPayload($calendarEvent), 'Event updated.');
    }

    // DELETE /api/v1/calendar/events/{calendarEvent}
    public function destroy(Request $request, CalendarEvent $calendarEvent): JsonResponse
    {
        if (!$this->canEdit($request->user(), $calendarEvent)) {
            return $this->error('You cannot delete this event.', [], 403);
        }

        $calendarEvent->delete();

        return $this->success(message: 'Event deleted.');
    }

    private function canEdit($user, CalendarEvent $event): bool
    {
        if ($user->role === 'admin' || $user->role === 'pm') {
            return true;
        }

        return (int) $event->created_by === (int) $user->id;
    }

    private function validateEvent(Request $request, bool $partial = false): array
    {
        $required = $partial ? 'sometimes' : 'required';

        $validated = $request->validate([
            'title'       => [$required, 'string', 'max:160'],
            'subtitle'    => ['nullable', 'string', 'max:160'],
            'description' => ['nullable', 'string', 'max:2000'],
            'type'        => [$required, Rule::in(CalendarEvent::TYPES)],
            'tone'        => ['nullable', Rule::in(CalendarEvent::TONES)],
            'project_id'  => ['nullable', 'integer'],
            'starts_at'   => [$required, 'date'],
            'ends_at'     => [$required, 'date', 'after:starts_at'],
            'all_day'     => ['sometimes', 'boolean'],
        ]);

        if (isset($validated['starts_at'])) {
            $validated['starts_at'] = Carbon::parse($validated['starts_at']);
        }
        if (isset($validated['ends_at'])) {
            $validated['ends_at'] = Carbon::parse($validated['ends_at']);
        }

        return $validated;
    }

    private function eventPayload(CalendarEvent $event): array
    {
        return [
            'id'           => $event->id,
            'source'       => 'calendar',
            'editable'     => true,
            'type'         => $event->type,
            'title'        => $event->title,
            'subtitle'     => $event->subtitle,
            'description'  => $event->description,
            'tone'         => $event->tone,
            'all_day'      => (bool) $event->all_day,
            'starts_at'    => $event->starts_at?->toIso8601String(),
            'ends_at'      => $event->ends_at?->toIso8601String(),
            'project_id'   => $event->project_id,
            'project_name' => $event->project?->name,
            'created_by'   => $event->created_by,
            'creator_name' => $event->creator?->name,
        ];
    }
}
