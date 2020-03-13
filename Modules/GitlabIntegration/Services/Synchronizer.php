<?php

namespace Modules\GitlabIntegration\Services;

use App\Models\Project;
use App\Models\Task;
use App\Models\User;
use Log;
use Modules\GitlabIntegration\Entities\ProjectRelation;
use Modules\GitlabIntegration\Entities\TaskRelation;
use Modules\GitlabIntegration\Helpers\GitlabApi;

/**
 * Class Synchronizer
 * @package Modules\GitlabIntegration\Services
 */
class Synchronizer
{
    public const COMPANY_ID = 'company_id';
    public const NAME = 'name';
    public const DESCRIPTION = 'description';
    public const IMPORTANT = 'important';

    public const PROJECT_ID = 'project_id';
    public const TASK_NAME = 'task_name';
    public const ACTIVE = 'active';
    public const USER_ID = 'user_id';
    public const ASSIGNED_BY = 'assigned_by';
    public const URL = 'url';
    public const PRIORITY_ID = 'priority_id';

    public function synchronizeAll()
    {
        $users = User::whereId(30)->get();
        foreach ($users as $user) {
            $this->synchronize($user);
        }
    }

    public function synchronize(User $user)
    {
        $api = GitlabApi::buildFromUser($user);

        if (!$api) {
            Log::info("Can`t instantiate an API for user " . $user->full_name . "\n");
            return false;
        }

        try {
            $gitlabProjects = $api->getUserProjects();
        } catch (\Throwable $throwable) {
            Log::error("Projects cant be fetched for user " . $user->full_name . "\n");
            Log::error($throwable);
            return false;
        }

        $this->syncProjects($gitlabProjects);

        try {
            $gitlabTasks = $api->getUserTasks();
        } catch (\Throwable $throwable) {
            Log::error("Tasks cant be fetched for user " . $user->full_name . "\n");
            Log::error($throwable);
            return false;
        }

        $this->syncTasks($gitlabTasks, $user->id);
        return true;
    }

    private function syncProjects(array $gitlabProjects)
    {
        $projectIds = array_map( function ($project) {
            return $project['id'];
        }, $gitlabProjects);

        foreach ($gitlabProjects as $gitlabProject) {
            $projectMapping = [
                self::COMPANY_ID  => 0,
                self::NAME        => $gitlabProject['name'] ?? 'Gitlab Project without Name ?!',
                self::DESCRIPTION => $gitlabProject['description'] ?? '',
                self::IMPORTANT   => false,
            ];

            $relation = ProjectRelation::whereGitlabId($gitlabProject['id'])->first();
            if (!$relation) {
                $project = Project::create($projectMapping);
                ProjectRelation::create([
                    'gitlab_id'  => $gitlabProject['id'],
                    'project_id' => $project->id,
                ]);
            } else {
                $project = Project::find($relation->project_id);

                if (!$project) {
                    $relation->delete();
                    continue;
                }

                $project->name = $projectMapping[self::NAME];
                $project->description = $projectMapping[self::DESCRIPTION];
                $project->save();
            }
        }

        $relationsToRemove = ProjectRelation::whereNotIn('gitlab_id', $projectIds)->get();
        foreach ($relationsToRemove as $relationToRemove) {
            $relationToRemove->delete();
        }
    }

    private function syncTasks(array $gitlabTasks, int $userID)
    {
        $taskIds = array_map( function ($task) {
            return $task['id'];
        }, $gitlabTasks);

        foreach ($gitlabTasks as $gitlabTask) {
            $projectID = ProjectRelation::where('gitlab_id', $gitlabTask['project_id'])->first()->project_id;
            if (!$projectID) {
                Log::error("Project ID for gilab issue wasn`t found! {$gitlabTasks['name'] }");
                continue;
            }

            $taskMapping = [
                self::TASK_NAME   => $gitlabTask['title'] ?? 'Gitlab Issue without Name',
                self::DESCRIPTION => $gitlabTask['description'] ?? '',
                self::PROJECT_ID  => $projectID,
                self::ACTIVE      => true,
                self::ASSIGNED_BY => 0,
                self::URL         => $gitlabTask['web_url'] ?? '',
                self::PRIORITY_ID => 2,
                self::IMPORTANT   => false,
                self::USER_ID     => $userID,
            ];

            $taskRelation = TaskRelation::find($gitlabTask['id']);

            if (!$taskRelation) {
                $task = Task::create($taskMapping);
                TaskRelation::create([
                    'gitlab_id' => $gitlabTask['id'],
                    'task_id'   => $task->id,
                    'gitlab_issue_iid' => $gitlabTask['iid'],
                ]);
            } else {
                $task = Task::find($taskRelation->task_id);

                if (!$task) {
                    $taskRelation->delete();
                    continue;
                }

                $taskRelation->gitlab_issue_iid = $gitlabTask['iid'];
                $taskRelation->save();

                $task->task_name = $taskMapping[self::TASK_NAME];
                $task->description = $taskMapping[self::DESCRIPTION];
                $task->user_id = $taskMapping[self::USER_ID];
                $task->save();
            }
        }

        $relationsToRemove = TaskRelation::whereNotIn('gitlab_id', $taskIds)->get();
        foreach ($relationsToRemove as $relationToRemove) {
            $internalTask = Task::find($relationToRemove->task_id);
            if ($internalTask && $internalTask->user_id == $userID) {
                $internalTask->active = 0;
                $internalTask->save();
            }
        }
    }
}
