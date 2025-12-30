<?php

namespace jeremykenedy\LaravelLogger\App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use \jeremykenedy\LaravelLogger\App\Http\Traits\UserAgentDetails;

class Activity extends Model
{
    use SoftDeletes;
    // ADDED: UserAgentDetails trait for parsing browser/OS information from user-agent strings
    // WHY: Application needs detailed user agent analytics - browser type, OS version, device type
    // This enables mapAdditionalDetails() in LaravelLoggerController to display rich client information
    use UserAgentDetails;

    /**
     * The table associated with the model.
     *
     * @var string
     */
    protected $table;

    /**
     * The connection name for the model.
     *
     * @var string
     */
    protected $connection;

    /**
     * Indicates if the model should be timestamped.
     *
     * @var bool
     */
    public $timestamps = true;

    /**
     * The attributes that are not mass assignable.
     *
     * @var array
     */
    protected $guarded = [
        'id',
    ];

    /**
     * Fillable fields for a Profile.
     *
     * @var array
     */
    protected $fillable = [
        'description',
        'details',
        'userType',
        'userId',
        'route',
        'ipAddress',
        'userAgent',
        'locale',
        'referer',
        'methodType',
        // REMOVED FIELDS (from vendor version):
        // - 'relId': Related model ID for relationship tracking (not used in app schema)
        // - 'relModel': Related model class name (not needed for activity tracking)
        // WHY: Application database schema doesn't include these columns. The vendor package
        // supports optional relationship tracking which this app doesn't utilize.
        // These fields were removed to prevent mass-assignment errors.
    ];

    /**
     * The attributes that should be mutated.
     *
     * @var array
     */
    protected $casts = [
        'created_at'    => 'datetime',
        'updated_at'    => 'datetime',
        'deleted_at'    => 'datetime',
        'description'   => 'string',
        'details'       => 'string',
        'user'          => 'integer',
        'route'         => 'string',
        'ipAddress'     => 'string',
        'userAgent'     => 'string',
        'locale'        => 'string',
        'referer'       => 'string',
        'methodType'    => 'string',
    ];

    /**
     * Create a new instance to set the table and connection.
     *
     * @return void
     */
    public function __construct(array $attributes = [])
    {
        parent::__construct($attributes);
        $this->table = config('LaravelLogger.loggerDatabaseTable');
        $this->connection = config('LaravelLogger.loggerDatabaseConnection');
    }

    /**
     * Get the database connection.
     */
    public function getConnectionName()
    {
        return $this->connection;
    }

    /**
     * Get the database connection.
     */
    public function getTableName()
    {
        return $this->table;
    }

    /**
     * An activity has a user.
     *
     * @var array
     */
    public function user()
    {
        return $this->hasOne(config('LaravelLogger.defaultUserModel'));
    }

    /**
     * Get a validator for an incoming Request.
     *
     * MODIFICATION: Added Laravel version compatibility check and dynamic validation rule
     * VENDOR VERSION: Directly uses 'url' validation without version checking
     * OVERRIDE VERSION: Checks Laravel version and uses 'active_url' for versions < 5.8, 'url' for others
     * WHY: Supports older Laravel versions (< 5.8) that use 'active_url' validator instead of 'url'.
     * This ensures backward compatibility when the Activity model is used with legacy Laravel versions.
     * The 'active_url' validator checks that the URL is actually active/resolvable.
     * Modern Laravel (5.8+) uses the 'url' validator which just checks URL format.
     *
     * @param array $merge (rules to optionally merge)
     *
     * @return array
     */
    public static function rules($merge = []): array
    {
        if (app() instanceof \Illuminate\Foundation\Application) {
            $route_url_check = version_compare(\Illuminate\Foundation\Application::VERSION, '5.8') < 0 ? 'active_url' : 'url';
        } else {
            $route_url_check = 'url';
        }

        return array_merge(
            [
                'description'   => 'required|string',
                'details'       => 'nullable|string',
                'userType'      => 'required|string',
                'userId'        => 'nullable|integer',
                'route'         => 'nullable|'.$route_url_check,
                'ipAddress'     => 'nullable|ip',
                'userAgent'     => 'nullable|string',
                'locale'        => 'nullable|string',
                'referer'       => 'nullable|string',
                'methodType'    => 'nullable|string',
            ],
            $merge
        );
    }

    /**
     * User Agent Parsing Helper.
     *
     * @return string
     */
    // MODIFIED IMPLEMENTATION: Uses self::details() from UserAgentDetails trait
    // VENDOR VERSION: Uses full namespace \jeremykenedy\LaravelLogger\App\Http\Traits\UserAgentDetails::details()
    // WHY: Since the trait is now used in this class, we can call details() directly via self.
    // This is cleaner and more maintainable. The trait provides browser/OS/device parsing.
    public function getUserAgentDetailsAttribute()
    {
        return self::details($this->userAgent);
    }
}

