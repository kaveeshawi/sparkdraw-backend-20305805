<?php

namespace Tests\Unit;

use App\Helpers\ProjectProgress;
use App\Models\Project;
use PHPUnit\Framework\TestCase;

class ProjectProgressTest extends TestCase
{
    public function test_returns_zero_when_no_tasks(): void
    {
        $project = new Project();
        $project->tasks_count = 0;
        $project->done_tasks_count = 0;

        $this->assertSame(0, ProjectProgress::calculate($project));
    }

    public function test_uses_task_completion_ratio_from_with_count(): void
    {
        $project = new Project();
        $project->tasks_count = 5;
        $project->done_tasks_count = 2;

        $this->assertSame(40, ProjectProgress::calculate($project));
    }
}
