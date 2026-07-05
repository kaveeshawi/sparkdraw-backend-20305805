<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Traits\ApiResponse;
use App\Models\Comment;
use App\Models\Project;
use App\Models\ProjectEvent;
use App\Models\Task;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class CommentController extends Controller
{
    use ApiResponse;

    // GET /api/v1/projects/{project}/tasks/{task}/comments
    public function index(Request $request, Project $project, Task $task): JsonResponse
    {
        if ($task->project_id !== $project->id) {
            return $this->notFound('Task not found on this project.');
        }

        $comments = $task->comments()
            ->with('user:id,name,role')
            ->orderBy('created_at')
            ->get()
            ->map(fn (Comment $c) => [
                'id'         => $c->id,
                'body'       => $c->body,
                'user_id'    => $c->user_id,
                'user_name'  => $c->user?->name,
                'user_role'  => $c->user?->role,
                'created_at' => $c->created_at,
            ]);

        return $this->success($comments);
    }

    // POST /api/v1/projects/{project}/tasks/{task}/comments
    public function store(Request $request, Project $project, Task $task): JsonResponse
    {
        if ($task->project_id !== $project->id) {
            return $this->notFound('Task not found on this project.');
        }

        $validated = $request->validate([
            'body' => ['required', 'string', 'min:1', 'max:5000'],
        ]);

        $user = $request->user();

        $comment = Comment::create([
            'agency_id'  => $project->agency_id,
            'project_id' => $project->id,
            'task_id'    => $task->id,
            'user_id'    => $user->id,
            'body'       => $validated['body'],
        ]);

        ProjectEvent::log($user->agency_id, $project->id, 'comment_added', [
            'comment_id' => $comment->id,
            'task_id'    => $task->id,
            'user_id'    => $user->id,
        ]);

        $comment->load('user:id,name,role');

        return $this->created([
            'id'         => $comment->id,
            'body'       => $comment->body,
            'user_name'  => $comment->user?->name,
            'created_at' => $comment->created_at,
        ], 'Comment added.');
    }
}
