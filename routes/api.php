<?php

use App\Http\Controllers\ApiController as A;
use Illuminate\Support\Facades\Route;

// Auto-prefixed with /api. Token middleware guards everything except /api/auth
// (the middleware itself skips that path). Mirrors the FastAPI /api router.
Route::middleware('shop.token')->group(function () {
    Route::get('/auth', [A::class, 'auth']);

    Route::get('/items', [A::class, 'items']);
    Route::get('/quick', [A::class, 'quick']);
    Route::get('/search', [A::class, 'search']);
    Route::post('/items', [A::class, 'addItem']);
    Route::post('/items/{id}/meta', [A::class, 'updateMeta']);
    Route::post('/items/{id}/info', [A::class, 'updateInfo']);
    Route::post('/items/{id}/restock', [A::class, 'restock']);
    Route::delete('/items/{id}', [A::class, 'deleteItem']);

    Route::post('/quick-add', [A::class, 'quickAdd']);
    Route::post('/import', [A::class, 'import']);

    Route::get('/lookup-barcode/{code}', [A::class, 'lookupBarcode']);
    Route::get('/barcode/{code}', [A::class, 'barcode']);
    Route::post('/items/{id}/barcode', [A::class, 'setBarcode']);

    Route::get('/items/{id}/options', [A::class, 'options']);
    Route::post('/items/{id}/options', [A::class, 'addOption']);
    Route::delete('/options/{oid}', [A::class, 'deleteOption']);

    Route::post('/categories/rename', [A::class, 'renameCategory']);

    Route::post('/items/{id}/image', [A::class, 'addImage']);
    Route::get('/items/{id}/images', [A::class, 'listImages']);
    Route::get('/items/{id}/image', [A::class, 'itemImage']);
    Route::get('/images/{iid}', [A::class, 'image']);
    Route::delete('/images/{iid}', [A::class, 'deleteImage']);

    Route::post('/items/{id}/price', [A::class, 'setPrice']);
    Route::post('/sale', [A::class, 'sale']);
    Route::post('/sale/{sid}/void', [A::class, 'voidSale']);

    Route::post('/parse', [A::class, 'parse']);
    Route::post('/parse-catalog', [A::class, 'parseCatalog']);
    Route::post('/assistant', [A::class, 'assistant']);

    Route::get('/today', [A::class, 'today']);
    Route::get('/report', [A::class, 'report']);
    Route::get('/best', [A::class, 'best']);
    Route::get('/low', [A::class, 'low']);
});
