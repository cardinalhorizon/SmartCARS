<?php

Route::group(['prefix' => 'smartcars', 'namespace' => 'Modules\SmartCARS\Http\Controllers'], function()
{
    Route::get('/frame.php', 'APIFrame@index');
    Route::post('/frame.php', 'APIFrame@index');
    Route::get('/install', function() {
        $exitCode = Artisan::call('module:migrate', [
            'module' => 'SmartCARS'
        ]);
        if ($exitCode === 0)
        {
            return response("smartCARS Module installed/updated successfully. Please use the following URL in your smartCARS settings on TFDi's Website: ". url('/').'/index.php/smartcars/frame.php');
        }
        return response($exitCode);
    });
});
