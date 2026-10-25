<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        if (!Schema::hasTable('communications')) {
        Schema::create('communications', function (Blueprint $table) {
            $table->engine = 'InnoDB';
            $table->bigIncrements('id');
            $table->unsignedInteger('author_id')->nullable();
            $table->string('legacy_key',100)->nullable()->unique();
            $table->string('delivery_token',32)->nullable();
            $table->string('title', 100);
            $table->string('kind', 16)->default('announcement');
            $table->string('status', 16)->default('draft')->index();
            $table->string('locale', 20)->default('');
            $table->string('editor', 16)->default('blocks');
            $table->longText('source')->nullable();
            $table->longText('blocks')->nullable();
            $table->text('audience')->nullable();
            $table->timestamp('published_at')->nullable();
            $table->timestamps();
        });
        }
        elseif (!Schema::hasColumn('communications','legacy_key')) {Schema::table('communications',fn(Blueprint $table)=>$table->string('legacy_key',100)->nullable()->unique());}
        if (!Schema::hasColumn('communications','delivery_token')) {Schema::table('communications',fn(Blueprint $table)=>$table->string('delivery_token',32)->nullable());}
        foreach (DB::table('options')->where('option_name', 'announcement')->orWhere('option_name', 'like', 'announcement_%')->get() as $option) {
            if (trim((string) $option->option_value) === '') {
                continue;
            }
            if (DB::table('communications')->where('legacy_key','option:'.$option->option_name)->exists()) {continue;}
            DB::table('communications')->insert(['legacy_key'=>'option:'.$option->option_name,'title' => '站点公告', 'kind' => 'announcement', 'status' => 'published',
                'locale' => $option->option_name === 'announcement' ? '' : substr($option->option_name, 13),
                'editor' => 'source', 'source' => $option->option_value, 'published_at' => now(), 'created_at' => now(), 'updated_at' => now()]);
        }
        DB::table('notifications')->where('type', App\Notifications\SiteMessage::class)->orderBy('id')->chunkById(200, function ($rows) {
            foreach ($rows as $row) {
                $data = json_decode($row->data, true);
                if (!is_array($data) || (isset($data['communication_id']) && DB::table('communications')->where('id',$data['communication_id'])->exists())) {
                    continue;
                }
                $id=DB::table('communications')->where('legacy_key','notification:'.$row->id)->value('id');
                $id ??= DB::table('communications')->insertGetId(['legacy_key'=>'notification:'.$row->id,'title' => mb_substr((string) ($data['title'] ?? '站内信'), 0, 100),
                    'kind' => 'message', 'status' => 'published', 'editor' => 'source', 'source' => $data['content'] ?? '',
                    'audience' => json_encode(['receiver' => 'uid', 'uid' => $row->notifiable_id]),
                    'published_at' => $row->created_at, 'created_at' => $row->created_at, 'updated_at' => $row->updated_at]);
                $data['communication_id'] = $id;
                DB::table('notifications')->where('id', $row->id)->update(['data' => json_encode($data, JSON_UNESCAPED_UNICODE)]);
            }
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('communications');
    }
};
