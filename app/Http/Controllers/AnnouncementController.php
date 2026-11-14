<?php

namespace App\Http\Controllers;

use App\Announcements\AccountManagerAnnouncements;
use App\Announcements\AnnouncementDispatcher;
use App\Models\Announcement;
use App\Models\AnnouncementRead;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Validation\Rule;

/**
 * Announcements for the app shell (bell, list "News", banner), read and dismissed per user. The client
 * keeps the last answer on the device (resources/js/announcements.js), so the news stay readable offline.
 */
class AnnouncementController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $user = $request->user();
        if (! AccountManagerAnnouncements::enabled()) {
            return response()->json(['data' => []]);
        }

        $announcements = Announcement::query()->visibleTo($user)->orderByDesc('publish_at')->orderByDesc('id')->get()
            ->filter(fn (Announcement $announcement) => $announcement->hasChannel('in_app') || $announcement->hasChannel('banner'));
        $reads = AnnouncementRead::query()->where('user_id', $user->id)
            ->whereIn('announcement_id', $announcements->modelKeys())->get()->keyBy('announcement_id');

        return response()->json([
            'data' => $announcements->map(fn (Announcement $announcement) => $announcement->toClient($user, $reads->get($announcement->id)))->values(),
        ]);
    }

    /**
     * Marks announcements as read (opened in "News") or dismissed (banner closed). Unknown or no longer
     * visible ids are ignored (sent later from a device that was offline).
     */
    public function mark(Request $request): Response
    {
        $validated = $request->validate([
            'read' => ['sometimes', 'array', 'max:200'],
            'read.*' => ['string', 'uuid'],
            'dismissed' => ['sometimes', 'array', 'max:200'],
            'dismissed.*' => ['string', 'uuid'],
        ]);
        $user = $request->user();

        foreach (['read' => 'read_at', 'dismissed' => 'dismissed_at'] as $key => $column) {
            $uuids = array_map('strtolower', $validated[$key] ?? []);
            if ($uuids === []) {
                continue;
            }

            Announcement::query()->visibleTo($user)->whereIn('account_manager_uuid', $uuids)->pluck('id')
                ->each(function (int $id) use ($user, $column): void {
                    $read = AnnouncementRead::query()->firstOrNew(['announcement_id' => $id, 'user_id' => $user->id]);
                    $read->{$column} ??= now();
                    $read->save();
                });
        }

        return response()->noContent();
    }

    /**
     * Setting "News by email" (all, maintenance notices only, none).
     */
    public function updateEmails(Request $request): Response
    {
        $validated = $request->validate(['announcement_emails' => ['required', Rule::in(AnnouncementDispatcher::EMAIL_SETTINGS)]]);
        $request->user()->forceFill($validated)->save();

        return response()->noContent();
    }
}
