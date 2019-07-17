<?php

/**
 * Welkome app configuration
 */

return [

    /*
    |--------------------------------------------------------------------------
    | Execution environment
    |--------------------------------------------------------------------------
    |
    | The application can be executed in two environments, in the web 
    | environment it will execute some additional functionalities.
    |
    */

    'env' => env('ENVIRONMENT', 'web'),

    'paginate' => 20, 

    'fields' => [
        'invoices' => [
            'id', 
            'number', 
            'discount', 
            'subvalue', 
            'taxes', 
            'value', 
            'open', 
            'status',
            'reservation',
            'for_company',
            'are_tourists',
            'for_job',
            'company_id',
            'user_id',
            'created_at'
        ],
        'rooms' => [
            'id', 
            'number', 
            'description', 
            'price', 
            'status', 
            'user_id'
        ],
        'guests' => [
            'id',
            'dni',
            'name',
            'last_name',
            'gender',
            'birthdate',
            'responsible_adult',
            'identification_type_id',
            'user_id',
            'status',
            'created_at'
        ],
        'products' => [
            'id',
            'description',
            'brand',
            'reference',
            'price',
            'quantity',
            'status',
            'user_id'
        ],
        'services' => [
            'id',
            'description',
            'price',
            'status',
            'user_id'
        ],
        'companies' => [
            'id',
            'tin',
            'business_name',
            'user_id',
            'created_at'
        ]
    ],
];