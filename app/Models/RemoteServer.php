<?php

namespace App\Models;

class RemoteServer extends BaseModel
{
    const HTTP_SERVER  = 1;
    const RCON_COMMAND = 2;
    const SQL_EXECUTE  = 3;

    protected $table = 'remote_servers';

    protected $fillable = [
        'name', 'type', 'url', 'host', 'port', 'username', 'password',
        'database', 'command', 'headers', 'body', 'description', 'is_active',
    ];

    protected $casts = [
        'type'      => 'integer',
        'port'      => 'integer',
        'is_active' => 'boolean',
        'headers'   => 'array',
        'body'      => 'array',
        'password'  => 'encrypted',
    ];

    public static function getServerTypeMap(): array
    {
        return [
            self::HTTP_SERVER  => __('remote-server.types.http_server'),
            self::RCON_COMMAND => __('remote-server.types.rcon_command'),
            self::SQL_EXECUTE  => __('remote-server.types.sql_execute'),
        ];
    }
}
