<?php

namespace App\Service;

use App\Models\User;
use Illuminate\Notifications\DatabaseNotification;

class DonorNotificationService
{
    /**
     * Categories the donor notifications screen filters by.
     *
     * @var array<int, string>
     */
    public const CATEGORIES = ['reminder', 'donation', 'screening', 'system'];

    /**
     * List the donor's own notifications, newest first.
     *
     * @param  array<string, mixed>  $filters
     * @return array<string, mixed>
     */
    public function list(User $user, array $filters): array
    {
        $query = $user->notifications()
            ->when(
                $filters['category'] ?? null,
                fn ($builder, $category) => $builder->where('data->category', $category)
            )
            ->when(
                array_key_exists('read', $filters) && $filters['read'] !== null,
                fn ($builder) => filter_var($filters['read'], FILTER_VALIDATE_BOOLEAN)
                    ? $builder->whereNotNull('read_at')
                    : $builder->whereNull('read_at')
            );

        $notifications = $query->paginate((int) ($filters['per_page'] ?? 20))->withQueryString();

        return [
            'notifications' => collect($notifications->items())
                ->map(fn (DatabaseNotification $notification): array => $this->format($notification))
                ->all(),
            'unread_count' => $user->unreadNotifications()->count(),
            'meta' => [
                'page' => $notifications->currentPage(),
                'per_page' => $notifications->perPage(),
                'total' => $notifications->total(),
                'last_page' => $notifications->lastPage(),
            ],
        ];
    }

    /**
     * Count the donor's unread notifications.
     *
     * @return array<string, int>
     */
    public function unreadCount(User $user): array
    {
        return ['unread_count' => $user->unreadNotifications()->count()];
    }

    /**
     * Mark one of the donor's own notifications as read.
     *
     * @return array<string, mixed>
     */
    public function markAsRead(User $user, string $notificationId): array
    {
        $notification = $user->notifications()->whereKey($notificationId)->first();

        if (! $notification) {
            abort(404, 'Notification not found.');
        }

        $notification->markAsRead();

        return $this->format($notification->refresh());
    }

    /**
     * Mark every unread notification for the donor as read.
     *
     * @return array<string, mixed>
     */
    public function markAllAsRead(User $user): array
    {
        $user->unreadNotifications()->update(['read_at' => now()]);

        return [
            'message' => 'All notifications marked as read.',
            'unread_count' => 0,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function format(DatabaseNotification $notification): array
    {
        $data = $notification->data;

        return [
            'id' => $notification->id,
            'category' => $data['category'] ?? 'system',
            'title' => $data['title'] ?? null,
            'desc' => $data['desc'] ?? null,
            'meta' => $data['meta'] ?? null,
            'icon' => $data['icon'] ?? null,
            'tone' => $data['tone'] ?? null,
            'action_label' => $data['action_label'] ?? null,
            'action_route' => $data['action_route'] ?? null,
            'read' => $notification->read_at !== null,
            'created_at' => $notification->created_at?->toIso8601String(),
        ];
    }
}
