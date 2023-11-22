<?php

declare(strict_types=1);

namespace Mateusjatenee\Persist\Tests;

use Illuminate\Contracts\Events\Dispatcher;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Illuminate\Support\Facades\Schema;
use Mateusjatenee\Persist\Persist;
use Mockery;

class PersistTest extends TestCase
{
    use DatabaseMigrations;

    protected function defineDatabaseMigrationsAfterDatabaseRefreshed(): void
    {
        Schema::create('users', function (Blueprint $table) {
            $table->increments('id');
            $table->string('name');
        });

        Schema::create('posts', function (Blueprint $table) {
            $table->increments('id');
            $table->string('title');
            $table->unsignedInteger('user_id');
        });

        Schema::create('tags', function (Blueprint $table) {
            $table->increments('id');
            $table->string('tag');
        });

        Schema::create('post_tag', function (Blueprint $table) {
            $table->increments('id');
            $table->unsignedInteger('post_id');
            $table->unsignedInteger('tag_id');
        });

        Schema::create('post_details', function (Blueprint $table) {
            $table->increments('id');
            $table->string('description');
            $table->unsignedInteger('post_id');
        });

        Schema::create('comments', function (Blueprint $table) {
            $table->increments('id');
            $table->string('comment');
            $table->nullableMorphs('commentable');
        });
    }

    public function testPersistSavesAHasOneRelationship()
    {
        $user = UserX::create(['name' => 'Mateus']);
        $post = PostX::make(['title' => 'Test title', 'user_id' => $user->id]);
        $details = PostDetails::make(['description' => 'Test description']);

        $post->details = $details;
        $post->persist();

        $this->assertEquals(1, $post->id);
        $this->assertEquals(1, $details->id);
        $this->assertTrue($details->fresh()->post->is($post));
    }

    public function testPersistSavesAHasManyRelationship()
    {
        UserX::create(['name' => 'First test']); // So user starts at ID 2
        $user = UserX::make(['name' => 'Test']);
        $post = PostX::make(['title' => 'Test title']);
        $user->posts->push($post);

        $user->persist();

        $this->assertEquals(2, $user->id);
        $this->assertEquals(1, $post->id);
        $this->assertTrue($post->fresh()->user->is($user));
    }

    public function testPersistSavesABelongsToRelationship()
    {
        $post = PostX::make(['title' => 'Test title']);
        $post->user()->associate($user = UserX::make(['name' => 'Test']));

        $post->persist();

        $this->assertEquals(1, $user->id);
        $this->assertEquals(1, $post->id);
        $this->assertTrue($post->fresh()->user->is($user));
    }

    public function testPersistSavesAMorphOneRelationship()
    {
        $user = UserX::create(['name' => 'Mateus']);
        $post = PostX::make(['title' => 'Test title', 'user_id' => $user->id]);
        $comment = CommentX::make(['comment' => 'Test comment']);
        $post->comment = $comment;

        $post->persist();

        $this->assertEquals(1, $post->id);
        $this->assertEquals(1, $comment->id);
        $this->assertTrue($post->fresh()->comment->is($comment));
    }

    public function testPersistSavesAMorphManyRelationship()
    {
        $user = UserX::create(['name' => 'Mateus']);
        $post = PostX::make(['title' => 'Test title', 'user_id' => $user->id]);
        $post->comments->push($comment = CommentX::make(['comment' => 'Test comment']));

        $post->persist();

        $this->assertEquals(1, $post->id);
        $this->assertEquals(1, $comment->id);
        $this->assertTrue($post->comments->first()->is($comment));
    }

    public function testPersistSavesAMorphToRelationship()
    {
        $user = UserX::create(['name' => 'Mateus']);
        $post = PostX::make(['title' => 'Test title', 'user_id' => $user->id]);
        $comment = CommentX::make(['comment' => 'Test comment']);
        $comment->commentable()->associate($post);
        $comment->persist();

        $this->assertEquals(1, $post->id);
        $this->assertEquals(1, $comment->id);
        $this->assertTrue($comment->commentable->is($post));
    }

    public function testPersistSavesABelongsToManyRelationship()
    {
        $user = UserX::create(['name' => 'Mateus']);
        $post = PostX::make(['title' => 'Test title', 'user_id' => $user->id]);
        $tag = TagX::make(['tag' => 'Test tag']);
        $post->tags->push($tag);

        $post->persist();

        $this->assertEquals(1, $post->id);
        $this->assertEquals(1, $tag->id);
        $this->assertTrue($post->tags->first()->is($tag));
    }

    public function testPersistReturnsFalseIfBelongsToSaveFails()
    {
        $post = PostX::make(['title' => 'Test title']);
        $user = UserX::make(['name' => 'Test']);
        $user->setEventDispatcher($events = Mockery::mock(Dispatcher::class));
        $events->expects('until')->with('eloquent.saving: '.get_class($user), $user)->andReturns(false);

        $post->user()->associate($user);

        $this->assertFalse($post->persist());
    }

    public function testPersistReturnsFalseIfRelationshipsFail()
    {
        $post = PostX::make(['title' => 'Test title']);
        $user = UserX::make(['name' => 'Test']);
        Model::setEventDispatcher($events = Mockery::mock(Dispatcher::class));
        $events->makePartial();
        $events->expects('dispatch')->times(3)->andReturn();
        $events->expects('until')->times(3)->andReturn(true);
        $events->expects('until')->with('eloquent.saving: '.get_class($post), $post)->andReturn(false);
        $user->save();

        $user->posts->push($post);

        $this->assertFalse($user->persist());
    }
}

class UserX extends Model
{
    use Persist;

    public $timestamps = false;

    protected $guarded = [];

    protected $table = 'users';

    public function posts()
    {
        return $this->hasMany(PostX::class, 'user_id');
    }
}

class PostX extends Model
{
    use Persist;

    public $timestamps = false;

    protected $guarded = [];

    protected $table = 'posts';

    public function tags()
    {
        return $this->belongsToMany(TagX::class, 'post_tag', 'post_id', 'tag_id');
    }

    public function details()
    {
        return $this->hasOne(PostDetails::class, 'post_id');
    }

    public function comment()
    {
        return $this->morphOne(CommentX::class, 'commentable');
    }

    public function comments()
    {
        return $this->morphMany(CommentX::class, 'commentable');
    }

    public function user()
    {
        return $this->belongsTo(UserX::class, 'user_id');
    }
}

class PostDetails extends Model
{
    use Persist;

    public $timestamps = false;

    protected $guarded = [];

    protected $table = 'post_details';

    public function post()
    {
        return $this->belongsTo(PostX::class, 'post_id');
    }
}

class CommentX extends Model
{
    use Persist;

    public $timestamps = false;

    protected $guarded = [];

    protected $table = 'comments';

    public function commentable()
    {
        return $this->morphTo('commentable');
    }
}

class TagX extends Model
{
    use Persist;

    public $timestamps = false;

    protected $guarded = [];

    protected $table = 'tags';

    public function posts()
    {
        return $this->belongsToMany(PostX::class, 'post_tag', 'tag_id', 'post_id');
    }
}
