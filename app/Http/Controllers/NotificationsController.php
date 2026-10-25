<?php

namespace App\Http\Controllers;

use App\Models\User;
use App\Notifications;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Notification;
use League\CommonMark\GithubFlavoredMarkdownConverter;

class NotificationsController extends Controller
{
    public function send(Request $request)
    {
        $data = $request->validate([
            'receiver' => 'required|in:all,normal,uid,email',
            'uid' => 'required_if:receiver,uid|nullable|integer|exists:users',
            'email' => 'required_if:receiver,email|nullable|email|exists:users',
            'title' => 'required|max:20',
            'content' => 'string|nullable',
        ]);

        $communication = \App\Models\Communication::create(['author_id' => $request->user()->uid, 'title' => $data['title'], 'kind' => 'message', 'status' => 'published', 'editor' => 'source', 'source' => $data['content'],
            'audience' => array_intersect_key($data, array_flip(['receiver', 'uid', 'email'])), 'published_at' => now(), 'delivery_token'=>bin2hex(random_bytes(16))]);
        $notification = new Notifications\SiteMessage($data['title'], $data['content']);

        switch ($data['receiver']) {
            case 'all':
                $users = User::all();
                break;
            case 'normal':
                $users = User::where('permission', User::NORMAL)->get();
                break;
            case 'uid':
                $users = User::where('uid', $data['uid'])->get();
                break;
            case 'email':
                $users = User::where('email', $data['email'])->get();
                break;
        }
        $notification->communicationId = $communication->id;
        $notification->deliveryToken = $communication->delivery_token;
        Notification::send($users, $notification);

        session(['sentResult' => trans('admin.notifications.send.success')]);

        return redirect('/admin');
    }

    public function all()
    {
        return app(\App\Services\Communications::class)->inboxQuery(auth()->user())->whereNull('read_at')->get()
            ->map(fn ($notification) => [
                'id' => $notification->id,
                'title' => app(\App\Services\Communications::class)->title($notification),
            ]);
    }

    public function read($id)
    {
        $notification = auth()
            ->user()
            ->unreadNotifications
            ->first(fn ($notification) => $notification->id === $id);
        abort_unless($notification && app(\App\Services\Communications::class)->visible($notification), 404);
        $notification->markAsRead();

        $converter = new GithubFlavoredMarkdownConverter();

        return [
            'title' => app(\App\Services\Communications::class)->title($notification),
            'content' => app(\App\Services\Communications::class)->content($notification),
            'time' => $notification->created_at->toDateTimeString(),
        ];
    }
}
