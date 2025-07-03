<?php
declare(strict_types=1);

return [
    // Токен вашего бота
    'BOT_TOKEN'      => 'ВАШ_BOT_TOKEN',
    // Секретный токен для webhook
    'WEBHOOK_SECRET' => 'ВАШ_SECRET_TOKEN',

    // Настройки базы данных
    'DB' => [
        'HOST'     => '127.0.0.1',
        'PORT'     => 3306,
        'NAME'     => 'telegram_bot',
        'USER'     => 'db_user',
        'PASSWORD' => 'db_password',
        'CHARSET'  => 'utf8mb4',
    ],

    // Список администраторов
    'ADMINS' => [
        123456789,  // ID1
        987654321,  // ID2
    ],

    // Rate-limit для рассылок
    'RATE_LIMIT' => [
        'default' => [
            'batch_size'      => 30,   // размер батча
            'delay_ms'        => 1000, // задержка между батчами
            'msg_delay_ms'    => 40,   // задержка между сообщениями
        ],
        'paid'    => [
            'batch_size'      => 1000,
            'delay_ms'        => 1000,
            'msg_delay_ms'    => 40,
        ],
    ],

    // Максимальное число сообщений, отправляемых за один запуск run_broadcast.php
    // 0 означает отсутствие ограничения. Значение можно переопределить
    // аргументом CLI.
    'MAX_MESSAGES_PER_RUN' => 500,

    // URL вашего webhook (например, https://yourdomain.com/bot.php)
    'WEBHOOK_URL' => 'https://yourdomain.com/path/to/bot.php',
];
