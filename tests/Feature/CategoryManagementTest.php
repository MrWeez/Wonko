<?php

use App\Models\Category;
use App\Models\User;
use Livewire\Livewire;

test('users can create, edit, reorder and delete their categories', function () {
    $user = User::factory()->create();

    $component = Livewire::actingAs($user)->test('pages::categories');

    $component
        ->set('categoryName', 'Food')
        ->set('categoryColor', '#ff0000')
        ->call('saveCategory')
        ->assertHasNoErrors();

    $food = Category::query()->where('name', 'Food')->firstOrFail();

    $component
        ->set('categoryName', 'Transport')
        ->set('categoryColor', '#00ff00')
        ->call('saveCategory')
        ->assertHasNoErrors();

    $transport = Category::query()->where('name', 'Transport')->firstOrFail();

    expect($food->fresh()->color)->toBe('#FF0000')
        ->and($transport->fresh()->sort_order)->toBeGreaterThan($food->fresh()->sort_order);

    $component
        ->call('moveCategory', $transport->id, 'up');

    expect($transport->fresh()->sort_order)->toBeLessThan($food->fresh()->sort_order);

    $component
        ->call('editCategory', $food->id)
        ->set('categoryName', 'Groceries')
        ->call('saveCategory')
        ->assertHasNoErrors();

    expect(Category::query()->find($food->id)->name)->toBe('Groceries');

    $component->call('deleteCategory', $transport->id);

    expect(Category::query()->find($transport->id))->toBeNull();
});

test('category names and colors are validated', function () {
    $user = User::factory()->create();

    Livewire::actingAs($user)
        ->test('pages::categories')
        ->set('categoryName', '')
        ->set('categoryColor', 'red')
        ->call('saveCategory')
        ->assertHasErrors(['categoryName', 'categoryColor']);
});

test('users cannot see another users categories', function () {
    $user = User::factory()->create();
    $otherUser = User::factory()->create();
    $category = Category::factory()->for($otherUser)->create(['name' => 'Private']);

    Livewire::actingAs($user)
        ->test('pages::categories')
        ->assertDontSee('Private');

    expect(Category::query()->find($category->id))->toBeNull();
});
