<?php

namespace App\Services;

use App\Models\Communication;
use App\Models\User;
use App\Notifications\SiteMessage;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;

class Communications
{
    public function save(array $data, ?Communication $message, int $author): Communication
    {
        return DB::transaction(function () use ($data, $message, $author) {
            if ($data['kind'] === 'announcement') {
                return $this->saveAnnouncement($data, $author);
            }
            $message ??= new Communication();
            $wasPublished = $message->exists && $message->status === 'published';
            $wasInbox = $wasPublished && in_array($message->kind, ['message', 'both'], true);
            $message->fill(array_intersect_key($data, array_flip(['title', 'kind', 'editor', 'source', 'blocks', 'locale', 'audience'])));
            $message->author_id = $author;
            $message->status = $data['operation'] === 'publish' ? 'published' : 'draft';
            if ($message->status === 'published' && !$wasPublished) {
                $message->published_at = now();
            }
            $startDelivery = $message->status === 'published' && in_array($message->kind, ['message', 'both'], true) && !$wasInbox;
            if ($startDelivery) {
                $message->delivery_token = bin2hex(random_bytes(16));
            }
            $message->save();
            if ($message->status === 'published' && in_array($message->kind, ['message', 'both'], true)) {
                if ($startDelivery) {
                    $this->deliver($message);
                } else {
                    $this->synchronize($message);
                }
            }

            if ($message->kind === 'both' && $message->status === 'published') {
                $this->saveAnnouncement($data, $author, $message->id);
            }

            return $message;
        });
    }

    private function saveAnnouncement(array $data, int $author, ?int $origin = null): Communication
    {
        $locale = $data['locale'] ?? '';
        $announcement = Communication::where('kind', 'announcement')->where('locale', $locale)->latest('id')->first() ?? new Communication();
        $announcement->fill(array_intersect_key($data, array_flip(['title', 'editor', 'source', 'blocks'])));
        $announcement->kind = 'announcement';
        $announcement->locale = $locale;
        $announcement->author_id = $author;
        $announcement->audience = $origin ? ['communication_id' => $origin] : null;
        $announcement->status = $data['operation'] === 'publish' ? 'published' : 'draft';
        if ($announcement->status === 'published') {
            $announcement->published_at = now();
        }
        $announcement->save();

        return $announcement;
    }

    public function currentAnnouncement(): ?Communication
    {
        foreach (array_unique([app()->getLocale(), '']) as $locale) {
            $announcement = Communication::where('locale', $locale)->where(function ($query) {
                $query->where('kind', 'announcement')->orWhere(fn ($both) => $both->where('kind', 'both')->where('status', 'published'));
            })->orderByDesc('updated_at')->orderBy('kind')->orderByDesc('id')->first();
            if ($announcement) {
                return $announcement;
            }
        }

        return null;
    }

    private function payload(Communication $message): array
    {
        return ['title' => $message->title, 'content' => app(CommunicationRenderer::class)->render($message->toArray(), 'mail-'.$message->id),
            'format' => 'html', 'communication_id' => $message->id, 'delivery_token' => $message->delivery_token];
    }

    private function deliver(Communication $message): void
    {
        $audience = $message->audience ?? ['receiver' => 'all'];
        $query = User::query();
        match ($audience['receiver'] ?? 'all') {
            'normal' => $query->where('permission', User::NORMAL),
            'uid' => $query->where('uid', $audience['uid']),
            'email' => $query->where('email', $audience['email']),
            default => $query->where('permission', '>=', User::NORMAL),
        };
        $data = $this->payload($message);
        $query->chunkById(200, function ($users) use ($data, $message) {
            Notification::sendNow($users, new SiteMessage($message->title, $data['content'], $message->id, $message->delivery_token));
        }, 'uid');
    }

    private function synchronize(Communication $message): void
    {
        DB::table('notifications')->where('data->communication_id', $message->id)->where('data->delivery_token', $message->delivery_token)->update(['data' => json_encode($this->payload($message), JSON_UNESCAPED_UNICODE), 'updated_at' => now()]);
    }

    public function withdraw(Communication $message): void
    {
        DB::transaction(function () use ($message) {
            $message->update(['status' => 'withdrawn']);
            if ($message->kind === 'both') {
                Communication::where('kind', 'announcement')->where('audience->communication_id', $message->id)->update(['status' => 'withdrawn']);
            }
        });
    }

    public function inboxQuery(User $user)
    {
        return $user->notifications()->where(function ($query) {
            $query->whereNull('data->communication_id')->orWhereExists(function ($active) {
                $active->selectRaw('1')->from('communications')->whereColumn('communications.id', 'notifications.data->communication_id')
                    ->where('communications.status', 'published')->whereIn('communications.kind', ['message', 'both'])
                    ->where(function ($token) {
                        $token->whereColumn('communications.delivery_token', 'notifications.data->delivery_token')->orWhere(function ($legacy) {
                            $legacy->whereNull('communications.delivery_token')->whereNull('notifications.data->delivery_token');
                        });
                    });
            });
        });
    }

    public function visible($notification): bool
    {
        $id = $notification->data['communication_id'] ?? null;

        return !$id || Communication::whereKey($id)->where('status', 'published')->whereIn('kind', ['message', 'both'])->where('delivery_token', $notification->data['delivery_token'] ?? null)->exists();
    }

    public function title($notification): string
    {
        $id = $notification->data['communication_id'] ?? null;

        return (string) ($id ? Communication::whereKey($id)->value('title') : ($notification->data['title'] ?? '站内信'));
    }

    public function content($notification): string
    {
        $data = $notification->data;
        if (!empty($data['communication_id'])) {
            $message = Communication::find($data['communication_id']);

            return $message ? app(CommunicationRenderer::class)->render($message->toArray(), 'mail-'.$message->id) : '';
        }

        return app(CommunicationRenderer::class)->render(['editor' => 'source', 'source' => $data['content'] ?? ''], 'legacy-'.str_replace('-', '', $notification->id));
    }

    public function announcements(): string
    {
        $announcement = $this->currentAnnouncement();

        return $announcement && $announcement->status === 'published'
            ? app(CommunicationRenderer::class)->render($announcement->toArray(), 'announcement-'.$announcement->id)
            : '';
    }
}
