<?php
include("header.php");

// --- 1. LECTURE DES COORDONNÉES DEPUIS CONFIG/APP.CONF ---
$app_conf_path = __DIR__ . '/config/app.conf';
$lat = null;
$lon = null;

if (file_exists($app_conf_path)) {
    $app_config = parse_ini_file($app_conf_path, true);
    if (isset($app_config['location'])) {
        if (!empty($app_config['location']['lat'])) {
            $lat = trim($app_config['location']['lat'], '"\' ');
        }
        if (!empty($app_config['location']['lon'])) {
            $lon = trim($app_config['location']['lon'], '"\' ');
        }
    }
}

// Fallback de sécurité (Hamburg)
$lat = $lat ?? 53.5500;
$lon = $lon ?? 10.0000;

// --- 2. GESTION DU CACHE FILE LOCALE (OPEN-METEO 15 MIN) ---
$cache_file = __DIR__ . '/data/openmeteo_cache.json';
$cache_ttl  = 900; // 15 minutes
$cloud_raw  = false;

if (!file_exists($cache_file) || (time() - filemtime($cache_file)) > $cache_ttl) {
    $open_meteo_url = "https://api.open-meteo.com/v1/forecast?latitude={$lat}&longitude={$lon}&current_weather=true&hourly=temperature_2m,relative_humidity_2m,weathercode&timezone=Europe%2FBerlin&forecast_days=2";
    $ctx = stream_context_create(['http' => ['timeout' => 3]]);
    $fetched_data = @file_get_contents($open_meteo_url, false, $ctx);
    
    if ($fetched_data) {
        $cloud_raw = $fetched_data;
        @file_put_contents($cache_file, $fetched_data);
    }
}

if (!$cloud_raw && file_exists($cache_file)) {
    $cloud_raw = @file_get_contents($cache_file);
}

$api_data = $cloud_raw ? json_decode($cloud_raw, true) : [];

$cloud_temp = isset($api_data['current_weather']['temperature']) ? round($api_data['current_weather']['temperature'], 1) : '--';
$cloud_wind = isset($api_data['current_weather']['windspeed']) ? round($api_data['current_weather']['windspeed'], 1) : '--';
$cloud_code = $api_data['current_weather']['weathercode'] ?? null;

$current_hour = (int)date('H');
$cloud_hum    = isset($api_data['hourly']['relative_humidity_2m'][$current_hour]) ? $api_data['hourly']['relative_humidity_2m'][$current_hour] : '--';

if (!function_exists('get_wmo_weather_info')) {
    function get_wmo_weather_info($code) {
        if ($code === null) return ['label' => 'N/A', 'icon' => '☁️'];
        $code = (int)$code;
        switch ($code) {
            case 0: return ['label' => 'Dégagé', 'icon' => '☀️'];
            case 1: case 2: case 3: return ['label' => 'Nuageux', 'icon' => '🌤️'];
            case 45: case 48: return ['label' => 'Brouillard', 'icon' => '🌫️'];
            case 51: case 53: case 55:
            case 61: case 63: case 65:
            case 80: case 81: case 82: return ['label' => 'Pluie', 'icon' => '🌧️'];
            case 71: case 73: case 75:
            case 85: case 86: return ['label' => 'Neige', 'icon' => '❄️'];
            case 95: case 96: case 99: return ['label' => 'Orage', 'icon' => '⛈️'];
            default: return ['label' => 'Météo', 'icon' => '☁️'];
        }
    }
}

$wmo_info = get_wmo_weather_info($cloud_code);

// --- 3. RÉCUPÉRATION DES DONNÉES LOCALES (NETATMO + TASMOTA BADEZIMMER) ---
$weather_file = __DIR__ . '/data/weather.json';
$weather_json = file_exists($weather_file) ? json_decode(@file_get_contents($weather_file), true) : [];
$netatmo = [];

if (is_array($weather_json)) {
    foreach ($weather_json as $mod) {
        $raw_name = $mod['name'] ?? '';
        $name = $raw_name;

        if ($name === "Station (Innen)") $name = "Esszimmer";
        if ($name === "Keller") $name = "Arbeitszimmer";
        if ($name === "WG") $name = "Wintergarten";

        $mod['display_name'] = $name;
        $netatmo[$raw_name] = $mod;
    }
}

// --- 4. INTÉGRATION SPÉCIFIQUE DU CAPTEUR TASMOTA THR316D (192.168.0.67) ---
$live_cache_file = __DIR__ . '/data/sha_live.json';
$live_devices = [];
if (file_exists($live_cache_file)) {
    $live_data = json_decode(@file_get_contents($live_cache_file), true);
    $live_devices = $live_data['devices'] ?? [];
}

$bz_ip = "192.168.0.67";
$bz_temp = $live_devices[$bz_ip]['temperature'] ?? null;
$bz_hum  = $live_devices[$bz_ip]['humidity'] ?? null;
$bz_dew  = $live_devices[$bz_ip]['dew_point'] ?? null;

// Reconstitution directe via HTTP Status 10 si la RAM n'a pas encore reçu le paquet MQTT
if ($bz_temp === null || $bz_temp == 154) {
    $ctx = stream_context_create(['http' => ['timeout' => 1]]);
    $tasmota_raw = @file_get_contents("http://{$bz_ip}/cm?cmnd=Status%2010", false, $ctx);
    if ($tasmota_raw) {
        $tasmota_data = json_decode($tasmota_raw, true);
        $sns = $tasmota_data['StatusSNS']['SI7021'] ?? ($tasmota_data['SI7021'] ?? null);
        if ($sns) {
            $bz_temp = $sns['Temperature'] ?? '--';
            $bz_hum  = $sns['Humidity'] ?? '--';
            $bz_dew  = $sns['DewPoint'] ?? null;
        }
    }
}

if ($bz_temp !== null && $bz_temp != 154) {
    $netatmo['Badezimmer_Tasmota'] = [
        'name'         => 'Badezimmer_Tasmota',
        'display_name' => 'Badezimmer',
        'type'         => 'Indoor',
        'temp'         => $bz_temp,
        'hum'          => $bz_hum,
        'dew'          => $bz_dew
    ];
}

$local_temp = $netatmo['Aussen']['temp'] ?? null;
$local_hum  = $netatmo['Aussen']['hum'] ?? null;

$ecart = ($local_temp !== null && is_numeric($cloud_temp)) 
    ? round(abs($local_temp - $cloud_temp), 1) 
    : '--';

// Placement du module extérieur ('Aussen') en 1ère position
if (isset($netatmo['Aussen'])) {
    $aussen = $netatmo['Aussen'];
    unset($netatmo['Aussen']);
    $netatmo = ['Aussen' => $aussen] + $netatmo;
}
?>

<!-- 🌤️ BANDEAU OPEN-METEO -->
<div class="room-card weather-card-full">
    <div class="room-head">
        <div class="room-title">
            <span>☁️</span> OPEN-METEO
        </div>
        <div style="display: flex; align-items: center; gap: 10px;">
            <span class="badge badge-yellow">Δ <?= $ecart; ?>°C</span>
            <span class="badge badge-blue">Prévisions</span>
        </div>
    </div>
    <div class="weather-body">
        <div style="display: flex; justify-content: space-around; align-items: center; flex-wrap: wrap; gap: 15px;">
            <div class="temp-display" style="min-width: 220px;">
                <div class="temp-val"><?= $cloud_temp; ?><span class="temp-unit">°C</span></div>
            </div>
            <div class="info-row">
                <div class="badge-info badge-blue"><?= $wmo_info['icon']; ?> <?= $wmo_info['label']; ?></div>
                <div class="badge-info badge-blue">💧 HUM: <?= $cloud_hum; ?>%</div>
                <div class="badge-info badge-blue">💨 VENT: <?= $cloud_wind; ?> km/h</div>
            </div>
        </div>
        <div class="forecast-grid">
            <?php 
            for($i = 1; $i <= 4; $i++): 
                $target_hour = $current_hour + $i;
                $ft = isset($api_data['hourly']['temperature_2m'][$target_hour]) 
                    ? round($api_data['hourly']['temperature_2m'][$target_hour], 1) 
                    : '--'; 
                $f_code = $api_data['hourly']['weathercode'][$target_hour] ?? null;
                $f_icon = get_wmo_weather_info($f_code)['icon'];
            ?>
            <div class="forecast-item">
                <div class="forecast-time"><?= date("H:00", strtotime("+$i hour")); ?></div>
                <div class="forecast-temp"><?= $f_icon; ?> <?= $ft; ?>°</div>
            </div>
            <?php endfor; ?>
        </div>
    </div>
</div>

<!-- 🌡️ GRILLE UNIFIÉE DE TOUS LES MODULES -->
<div class="weather-grid">
    <?php if (!empty($netatmo)): ?>
        <?php foreach ($netatmo as $mod):
            $raw = $mod['name'] ?? '';
            if ($raw === 'Regenwasser') continue;

            $displayName = $mod['display_name'] ?? 'Inconnu';
            $type = $mod['type'] ?? 'Indoor';
            $temp = isset($mod['temp']) ? round($mod['temp'], 1) : '--';
            $hum = $mod['hum'] ?? '--';
            $co2 = $mod['co2'] ?? null;
            $dew = $mod['dew'] ?? null;
            $battery = isset($mod['battery']) ? (int)$mod['battery'] : null;

            $is_garten = ($raw === 'Aussen');
            if ($is_garten) {
                $displayName = 'STATION S.H.A.';
                $badgeText = 'Extérieur';
                $badgeClass = 'badge-blue';
                $icon = $rooms['Garten']['icon'] ?? $rooms['Haus']['icon'] ?? '🏡';
                $card_extra_class = 'weather-card-outdoor';
            } else {
                $badgeText = 'Intérieur';
                $badgeClass = 'badge-blue';
                $icon = $rooms[$displayName]['icon'] ?? '🌡️';
                $card_extra_class = '';
            }
        ?>
        <div class="room-card weather-card <?= $card_extra_class; ?>">
            <div class="room-head">
                <div class="room-title">
                    <span><?= $icon; ?></span> <?= strtoupper(htmlspecialchars($displayName)); ?>
                </div>
                <span class="badge <?= $badgeClass; ?>"><?= $badgeText; ?></span>
            </div>
            <div class="weather-body">
                <div class="temp-display">
                    <div class="temp-val"><?= $temp; ?><span class="temp-unit">°C</span></div>
                </div>
                <div class="info-row">
                    <div class="badge-info badge-blue">💧 HUM: <?= $hum; ?>%</div>
                    <?php if (!empty($co2)): ?>
                    <div class="badge-info <?= ($co2 > 1000) ? 'badge-orange' : ''; ?>">CO2: <?= $co2; ?> ppm</div>
                    <?php endif; ?>
                    <?php if ($battery !== null): ?>
                    <div class="badge-info <?= ($battery <= 20) ? 'badge-orange' : ''; ?>">
                        <?= ($battery <= 20) ? '🪫' : '🔋'; ?> <?= $battery; ?>%
                    </div>
                    <?php endif; ?>
                    <?php if ($is_garten): ?>
                    <div class="badge-info">📍 Sangenstedt</div>
                    <?php endif; ?>
                </div>
            </div>
        </div>
        <?php endforeach; ?>
    <?php endif; ?>
</div>

<?php include("footer.php"); ?>