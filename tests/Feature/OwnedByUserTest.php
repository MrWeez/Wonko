<?php

use App\Models\User;
use App\Models\UserOwnedModel;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

test('OwnedByUser scopes records to their owning user', function () {
    Schema::create('owned_records', function (Blueprint $table) {
        $table->id();
        $table->foreignId('user_id')->constrained()->cascadeOnDelete();
        $table->string('name');
        $table->timestamps();
    });

    $record = new class extends UserOwnedModel
    {
        protected $table = 'owned_records';

        protected $guarded = [];
    };

    $userA = User::factory()->create();
    $userB = User::factory()->create();

    $recordFromA = $record::createForUser($userA, ['name' => 'from A']);
    $recordFromB = $record::createForUser($userB, ['name' => 'from B']);

    expect($record::query()->count())->toBe(0);

    $this->actingAs($userA);

    expect($record::query()->pluck('name'))->toContain('from A')->not->toContain('from B');
    expect($record::forUser($userB)->count())->toBe(0);

    expect($recordFromA->isOwnedBy($userA))->toBeTrue();
    expect($recordFromA->isOwnedBy($userB))->toBeFalse();

    expect($record::forUser($userA)->pluck('name'))->toHaveCount(1)->toContain('from A');
    expect($record::forUser($userA)->first()->name)->toBe('from A');

    expect($recordFromA->user->is($userA))->toBeTrue();
});

test('owned records cannot be created or reassigned for another authenticated user', function () {
    Schema::create('owned_records', function (Blueprint $table) {
        $table->id();
        $table->foreignId('user_id')->constrained()->cascadeOnDelete();
        $table->string('name');
        $table->timestamps();
    });

    $record = new class extends UserOwnedModel
    {
        protected $table = 'owned_records';

        protected $guarded = [];
    };

    $userA = User::factory()->create();
    $userB = User::factory()->create();

    $this->actingAs($userA);

    expect(fn () => $record::createForUser($userB, ['name' => 'for B']))
        ->toThrow(LogicException::class);

    $ownedRecord = $record::create(['name' => 'for A']);

    expect(fn () => $ownedRecord->update(['user_id' => $userB->id]))
        ->toThrow(LogicException::class);
});
