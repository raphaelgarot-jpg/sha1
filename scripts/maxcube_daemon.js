// scripts/maxcube_daemon.js
const fs = require('fs');
const path = require('path');
const { spawn } = require('child_process');

// --- DÉTECTION DYNAMIQUE DES CHEMINS PROJET (DOCKER / HÔTE) ---
const ROOT_DIR = path.join(__dirname, '..');
const APP_CONF = fs.existsSync('/app/config/app.conf') ? '/app/config/app.conf' : path.join(ROOT_DIR, 'config/app.conf');
const CMD_FILE = fs.existsSync('/app/data') ? '/app/data/heiz_cmd.json' : path.join(ROOT_DIR, 'data/heiz_cmd.json');
const JSON_OUTPUT = fs.existsSync('/app/data') ? '/app/data/heiz_out.json' : path.join(ROOT_DIR, 'data/heiz_out.json');

// Mémorisation de la dernière température "IST" valide connue par vanne
const lastKnownTemps = {};

/**
 * Extraction dynamique de l'IP et du Port depuis la section [Cube] de config/app.conf
 */
function getCubeConfig() {
    let ip = '192.168.0.22';
    let port = 62910;

    if (fs.existsSync(APP_CONF)) {
        try {
            const content = fs.readFileSync(APP_CONF, 'utf8');
            const lines = content.split('\n');
            let inCubeSection = false;

            for (let line of lines) {
                line = line.trim();
                if (line.startsWith('[') && line.endsWith(']')) {
                    inCubeSection = (line.toLowerCase() === '[cube]');
                    continue;
                }
                if (inCubeSection && line.includes('=')) {
                    const parts = line.split('=');
                    const key = parts[0].trim().toLowerCase();
                    const val = parts[1].split(';')[0].trim().replace(/^["']|["']$/g, '');
                    if (key === 'ip' && val) ip = val;
                    if (key === 'port' && val) port = parseInt(val, 10) || 62910;
                }
            }
        } catch (e) {
            console.error("⚠️ Erreur lors de la lecture de app.conf :", e.message);
        }
    }
    return { ip, port };
}

/**
 * Exécute maxcube-cli via spawn et temporise l'injection des commandes
 */
function runMaxcubeCli(commands = []) {
    const { ip, port } = getCubeConfig();
    const cliJsPath = path.join(ROOT_DIR, 'node_modules', 'maxcube-cli', 'maxcube-cli.js');

    return new Promise((resolve) => {
        let stdoutData = '';

        if (!fs.existsSync(cliJsPath)) {
            console.error("❌ maxcube-cli introuvable dans node_modules.");
            return resolve(null);
        }

        const child = spawn('node', [cliJsPath, ip, port], {
            stdio: ['pipe', 'pipe', 'pipe']
        });

        child.stdout.on('data', (chunk) => {
            stdoutData += chunk.toString();
        });

        // Temporisation (4s) pour laisser la socket TCP et Vorpal s'initialiser
        setTimeout(() => {
            commands.forEach((cmd, idx) => {
                setTimeout(() => {
                    child.stdin.write(`${cmd}\n`);
                }, idx * 2000);
            });

            setTimeout(() => {
                child.stdin.write("quit\n");
            }, (commands.length * 2000) + 1000);
        }, 4000);

        const killTimer = setTimeout(() => {
            try { child.kill(); } catch (_) {}
            resolve(stdoutData);
        }, 18000);

        child.on('close', () => {
            clearTimeout(killTimer);
            resolve(stdoutData);
        });

        child.on('error', (err) => {
            console.error("❌ Erreur lors du spawn de maxcube-cli :", err.message);
            clearTimeout(killTimer);
            resolve(null);
        });
    });
}

/**
 * Analyse le tableau ASCII retourné par status -v et reconstruit heiz_out.json
 */
function parseAndSaveStatus(rawOutput) {
    if (!rawOutput) {
        console.error("❌ Aucune donnée reçue de maxcube-cli.");
        return false;
    }

    const output = {
        system: {
            duty_val: 0,
            slots_val: 50,
            duty_color: 'var(--green)',
            last_scan: new Date().toISOString()
        },
        devices: {}
    };

    const dutyMatch = rawOutput.match(/duty_cycle:\s*(\d+)/i);
    const slotsMatch = rawOutput.match(/free_memory_slots:\s*(\d+)/i);

    if (dutyMatch) {
        const dVal = parseInt(dutyMatch[1], 10);
        output.system.duty_val = dVal;
        output.system.duty_color = (dVal > 80 ? 'var(--red)' : (dVal > 50 ? 'var(--orange)' : 'var(--green)'));
    }
    if (slotsMatch) {
        output.system.slots_val = parseInt(slotsMatch[1], 10);
    }

    const lines = rawOutput.split('\n');
    let headers = [];

    lines.forEach(line => {
        const trimmed = line.trim();
        if (!trimmed || trimmed.startsWith('┌') || trimmed.startsWith('├') || trimmed.startsWith('└') || trimmed.startsWith('Connected') || trimmed.startsWith('maxcube$') || trimmed.startsWith('Connection closed')) {
            return;
        }

        const columns = line.split(/[│|]/).map(col => col.trim());

        if (columns.some(c => c.toLowerCase().includes('rf addr'))) {
            headers = columns.map(c => c.toLowerCase());
            return;
        }

        if (headers.length > 0 && columns.length >= headers.length) {
            const rfIdVal = columns[headers.findIndex(h => h.includes('rf addr'))] || '';
            
            if (/^[0-9a-fA-F]{6}$/.test(rfIdVal)) {
                const rfId = rfIdVal.toLowerCase();
                
                const nameIdx = headers.findIndex(h => h.includes('name'));
                const roomIdx = headers.findIndex(h => h.includes('room'));
                const modeIdx = headers.findIndex(h => h.includes('mode'));
                const setpointIdx = headers.findIndex(h => h.includes('setpoint'));
                const tempIdx = headers.findIndex(h => h.includes('temp') && !h.includes('setpoint'));
                const battIdx = headers.findIndex(h => h.includes('battery'));
                const errIdx = headers.findIndex(h => h.includes('error') && !h.includes('link'));
                const linkErrIdx = headers.findIndex(h => h.includes('link_error'));

                const devName = nameIdx !== -1 ? columns[nameIdx] : '';
                const roomName = roomIdx !== -1 ? columns[roomIdx].toUpperCase() : '';
                const modeVal = modeIdx !== -1 ? (columns[modeIdx].toUpperCase() || 'AUTO') : 'AUTO';
                const setpointVal = setpointIdx !== -1 ? parseFloat(columns[setpointIdx]) : 20.0;
                const tempVal = tempIdx !== -1 ? parseFloat(columns[tempIdx]) : 0;
                
                const isBattLow = battIdx !== -1 ? (columns[battIdx].toLowerCase() === 'true') : false;
                const isErr = errIdx !== -1 ? (columns[errIdx].toLowerCase() === 'true') : false;
                const isLinkErr = linkErrIdx !== -1 ? (columns[linkErrIdx].toLowerCase() === 'true') : false;

                if (devName.toLowerCase().includes('fensterkontakt') || roomName.toLowerCase().includes('bbh') || (!devName.toLowerCase().includes('tm') && !roomName)) {
                    return;
                }

                let istDisplay = "--";
                if (!isNaN(tempVal) && tempVal > 0) {
                    lastKnownTemps[rfId] = tempVal.toString();
                    istDisplay = tempVal.toString();
                } else if (lastKnownTemps[rfId]) {
                    istDisplay = lastKnownTemps[rfId];
                }

                output.devices[rfId] = {
                    id: rfId,
                    room: roomName || 'INCONNU',
                    mode: modeVal,
                    soll: !isNaN(setpointVal) ? setpointVal : 20.0,
                    ist: istDisplay,
                    batt: isBattLow,
                    err: (isErr || isLinkErr),
                    boost: (modeVal === 'BOOST'),
                    win: (!isNaN(setpointVal) && setpointVal <= 5.1)
                };
            }
        }
    });

    const tmpFile = JSON_OUTPUT + '.tmp';
    try {
        fs.writeFileSync(tmpFile, JSON.stringify(output, null, 4), 'utf8');
        fs.renameSync(tmpFile, JSON_OUTPUT);
        console.log(`✅ Télémétrie complète enregistrée via maxcube-cli (${Object.keys(output.devices).length} vannes).`);
        return true;
    } catch (e) {
        console.error("❌ Erreur sauvegarde heiz_out.json :", e.message);
        return false;
    }
}

/**
 * Met à jour directement le cache heiz_out.json en mémoire/fichier sans rafraîchir status -v
 */
function updateLocalDeviceState(rfId, updates) {
    if (!fs.existsSync(JSON_OUTPUT)) return;
    try {
        const raw = fs.readFileSync(JSON_OUTPUT, 'utf8');
        const data = JSON.parse(raw);

        if (data.devices && data.devices[rfId]) {
            Object.assign(data.devices[rfId], updates);
            data.system.last_scan = new Date().toISOString();

            const tmpFile = JSON_OUTPUT + '.tmp';
            fs.writeFileSync(tmpFile, JSON.stringify(data, null, 4), 'utf8');
            fs.renameSync(tmpFile, JSON_OUTPUT);
            console.log(`⚡ Optimization Data : Cache heiz_out.json mis à jour directement pour la vanne [${rfId}].`);
        }
    } catch (e) {
        console.error("❌ Échec de la mise à jour directe du cache local :", e.message);
    }
}

/**
 * Effectue un status -v complet
 */
async function updateStatus() {
    const rawOutput = await runMaxcubeCli(['status -v']);
    return parseAndSaveStatus(rawOutput);
}

/**
 * Traite les commandes Web (temp, mode, scan) sans re-scanner le réseau si inutile
 */
async function processCommand(cmd) {
    if (!cmd || !cmd.fct) return;

    // Strict maintien en minuscules
    const rfId = (cmd.id || '').toLowerCase().trim();
    console.log(`🚀 Ordre reçu : ${cmd.fct} (Target: ${rfId}, Val: ${cmd.temp || 'N/A'})`);

    if (cmd.fct === 'temp' && rfId && cmd.temp) {
        const degrees = parseFloat(cmd.temp);
        console.log(`📡 Envoi à maxcube-cli : temp ${rfId} ${degrees}`);
        
        // 1. Exécution de la commande unique (SANS status -v)
        const cliOutput = await runMaxcubeCli([`temp ${rfId} ${degrees}`]);
        if (cliOutput) {
            console.log("-------------------- [RETOUR D'EXÉCUTION CLI] --------------------");
            console.log(cliOutput);
            console.log("------------------------------------------------------------------");
        }

        // 2. Injection directe dans le cache JSON local (Économie bande passante & batterie RF)
        updateLocalDeviceState(rfId, {
            soll: degrees,
            win: (degrees <= 5.1)
        });

    } else if (cmd.fct === 'mode' && rfId && cmd.temp) {
        const modeVal = cmd.temp.toUpperCase();
        console.log(`📡 Envoi à maxcube-cli : mode ${rfId} ${modeVal}`);
        
        // 1. Exécution de la commande unique (SANS status -v)
        const cliOutput = await runMaxcubeCli([`mode ${rfId} ${modeVal}`]);
        if (cliOutput) {
            console.log("-------------------- [RETOUR D'EXÉCUTION CLI] --------------------");
            console.log(cliOutput);
            console.log("------------------------------------------------------------------");
        }

        // 2. Injection directe dans le cache JSON local
        const isBoost = (modeVal === 'BOOST');
        const updates = {
            mode: modeVal,
            boost: isBoost
        };
        if (isBoost) {
            updates.soll = 30.0;
        }

        updateLocalDeviceState(rfId, updates);

    } else if (cmd.fct === 'scan') {
        await updateStatus();
    }
}

async function run() {
    const { ip, port } = getCubeConfig();
    console.log(`🐦‍🔥 Démon Thermique A.S.H.E.S. (maxcube-cli) démarré (Cible: ${ip}:${port})...`);

    let lastTelemetry = 0;

    while (true) {
        const now = Date.now();

        if (fs.existsSync(CMD_FILE)) {
            try {
                const rawCmd = fs.readFileSync(CMD_FILE, 'utf8');
                fs.unlinkSync(CMD_FILE);
                const cmd = JSON.parse(rawCmd);
                await processCommand(cmd);
                lastTelemetry = Date.now();
            } catch (e) {
                console.error("❌ Erreur traitement ticket commande :", e.message);
            }
        }

        if (now - lastTelemetry > 300000) {
            console.log("🔄 Actualisation télémétrie MAX! Cube via status -v...");
            await updateStatus();
            lastTelemetry = Date.now();
        }

        await new Promise(r => setTimeout(r, 2000));
    }
}

run();