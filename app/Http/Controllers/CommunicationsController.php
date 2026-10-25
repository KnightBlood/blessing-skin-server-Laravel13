<?php

namespace App\Http\Controllers;

use App\Models\Communication;
use App\Services\CommunicationRenderer;
use App\Services\Communications;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class CommunicationsController extends Controller
{
    public function index(Request $request, Communications $service)
    {
        $editing = $request->filled('edit') ? Communication::findOrFail($request->integer('edit')) : null;
        if ($request->boolean('announcement')) {
            $editing = $service->currentAnnouncement();
            if ($editing && $editing->kind === 'both') {
                $announcementSeed = array_merge($editing->toArray(), ['kind' => 'announcement']);
                $editing = null;
            }
        }
        $rows = Communication::whereIn('kind', ['message', 'both'])->orderByDesc('id')->paginate(15)->onEachSide(1)->withQueryString();
        foreach ($rows as $row) {
            $row->delivery_count = DB::table('notifications')->where('data->communication_id', $row->id)->count();
            $row->read_count = DB::table('notifications')->where('data->communication_id', $row->id)->whereNotNull('read_at')->count();
        }
        $initial = $editing?->toArray() ?? $announcementSeed ?? ['title' => ($request->boolean('announcement') ? '站点公告' : ''), 'kind' => ($request->boolean('announcement') ? 'announcement' : 'message'), 'editor' => 'blocks', 'blocks' => [], 'source' => '', 'locale' => '', 'audience' => ['receiver' => 'all']];
        foreach (['title', 'kind', 'editor', 'source', 'locale', 'blocks'] as $key) {
            $initial[$key] = $request->old($key, $initial[$key] ?? null);
        }
        $initial['audience'] = ['receiver' => $request->old('receiver', $initial['audience']['receiver'] ?? 'all'), 'uid' => $request->old('uid', $initial['audience']['uid'] ?? null), 'email' => $request->old('email', $initial['audience']['email'] ?? null)];

        return view('admin.communications', ['messages' => $rows, 'pagination' => \Illuminate\Pagination\UrlWindow::make($rows), 'editing' => $editing,
            'editor_data' => $initial, 'editor_open' => (bool) $editing || $request->boolean('announcement') || $request->boolean('new') || $request->session()->hasOldInput()]);
    }

    private function validateContent(Request $request): array
    {
        if ($request->has('blocks_json')) {
            $request->validate(['blocks_json' => 'required|json']);
            $request->merge(['blocks' => json_decode($request->input('blocks_json'), true)]);
        }
        $data = $request->validate([
            'title' => 'required|string|max:100', 'kind' => 'required|in:announcement,message,both',
            'editor' => 'required|in:source,blocks', 'source' => 'nullable|string|max:100000',
            'blocks' => 'nullable|array|max:80', 'blocks.*' => 'array:type,title,content,tone,expanded', 'blocks.*.type' => 'required|in:text,heading,alert,collapse,divider',
            'blocks.*.content' => 'nullable|string|max:10000', 'blocks.*.title' => 'nullable|string|max:200',
            'blocks.*.tone' => 'nullable|in:info,success,warning,danger,primary,secondary', 'blocks.*.expanded' => 'nullable|boolean',
            'locale' => 'nullable|string|max:20', 'receiver' => 'required|in:all,normal,uid,email',
            'uid' => 'required_if:receiver,uid|nullable|integer|exists:users,uid',
            'email' => 'required_if:receiver,email|nullable|email|exists:users,email',
            'operation' => 'required|in:draft,publish',
        ]);
        $data['locale'] = $data['locale'] ?? '';
        $data['audience'] = array_intersect_key($data, array_flip(['receiver', 'uid', 'email']));

        return $data;
    }

    public function store(Request $request, Communications $service)
    {
        $data = $this->validateContent($request);
        $message = $service->save($data, null, (int) $request->user()->uid);

        return redirect()->route('admin.communications.index', $message->kind === 'announcement' ? ['announcement' => 1] : ['edit' => $message->id])->with('communication_notice', $message->status === 'published' ? '内容已发布。' : '草稿已保存。');
    }

    public function update(Request $request, Communication $communication, Communications $service)
    {
        $data = $this->validateContent($request);
        // Published delivery recipients remain the original audience when editing.
        if ($communication->status === 'published') {
            $data['audience'] = $communication->audience;
        }
        $saved = $service->save($data, $communication, (int) $request->user()->uid);

        return redirect()->route('admin.communications.index', $saved->kind === 'announcement' ? ['announcement' => 1] : ['edit' => $saved->id])->with('communication_notice', '内容已更新。');
    }

    public function withdraw(Communication $communication, Communications $service)
    {
        $service->withdraw($communication);

        return redirect()->route('admin.communications.index')->with('communication_notice', '内容已撤回。');
    }

    public function importSource(Request $request, CommunicationRenderer $renderer)
    {
        $data = $request->validate(['source' => 'nullable|string|max:100000']);

        return response()->json(['blocks' => $renderer->importBlocks($data['source'] ?? '')]);
    }

    public function preview(Request $request, CommunicationRenderer $renderer)
    {
        $request->merge(['title' => $request->input('title') ?: '预览', 'kind' => 'announcement', 'receiver' => 'all', 'operation' => 'draft']);
        $data = $this->validateContent($request);

        return response()->json(['html' => $renderer->render($data)])->header('Cache-Control', 'no-store');
    }

    public function inbox(Request $request, Communications $service)
    {
        $messages = $service->inboxQuery($request->user())->latest()->paginate(20);
        $messages->setCollection($messages->getCollection()->filter(fn ($notification) => $service->visible($notification))->map(function ($notification) use ($service) {
            $notification->display_title = $service->title($notification);

            return $notification;
        })->values());

        return view('user.inbox', ['messages' => $messages]);
    }

    public function message(Request $request, string $id, Communications $service)
    {
        $message = $request->user()->notifications()->whereKey($id)->firstOrFail();
        abort_unless($service->visible($message), 404);
        $message->markAsRead();

        return view('user.message', ['message' => $message, 'content' => $service->content($message), 'message_title' => $service->title($message)]);
    }
}
