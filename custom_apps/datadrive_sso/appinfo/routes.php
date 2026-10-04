<?php

declare(strict_types=1);

return [
	'routes' => [
		['name' => 'Sso#login', 'url' => '/login', 'verb' => 'GET'],
		['name' => 'Sso#logout', 'url' => '/logout', 'verb' => 'GET'],
		['name' => 'Expiry#index', 'url' => '/expiry', 'verb' => 'GET'],
	],
];
