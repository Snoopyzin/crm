<?php

namespace Webkul\Admin\Http\Controllers;

use Illuminate\Http\JsonResponse;

class GuideController extends Controller
{
    /**
     * Returns the guides content merged with the logged in user's progress.
     */
    public function index(): JsonResponse
    {
        $completedSteps = auth()->guard('user')->user()->onboarding_progress ?? [];

        $guides = collect(config('guides'))
            ->map(function ($guide) use ($completedSteps) {
                $guide['steps'] = collect($guide['steps'])
                    ->map(function ($step) use ($completedSteps) {
                        $step['completed'] = in_array($step['id'], $completedSteps);

                        return $step;
                    })
                    ->all();

                $guide['completed_count'] = collect($guide['steps'])->where('completed', true)->count();

                $guide['total_count'] = count($guide['steps']);

                return $guide;
            })
            ->values();

        $totalSteps = $guides->sum('total_count');

        $totalCompleted = $guides->sum('completed_count');

        return response()->json([
            'guides' => $guides,
            'progress_percent' => $totalSteps ? (int) round(($totalCompleted / $totalSteps) * 100) : 0,
            'guides_completed' => $guides->filter(fn ($guide) => $guide['completed_count'] === $guide['total_count'])->count(),
            'guides_total' => $guides->count(),
            'steps_completed' => $totalCompleted,
            'steps_total' => $totalSteps,
        ]);
    }

    /**
     * Marks a single guide step as completed for the logged in user.
     */
    public function completeStep(string $stepId): JsonResponse
    {
        $user = auth()->guard('user')->user();

        $completedSteps = $user->onboarding_progress ?? [];

        if (! in_array($stepId, $completedSteps)) {
            $completedSteps[] = $stepId;

            $user->onboarding_progress = $completedSteps;

            $user->save();
        }

        return response()->json(['success' => true]);
    }
}
