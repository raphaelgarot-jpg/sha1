const MaxCube = require('maxcube');
const fs = require('fs');

const IP_CUBE = '192.168.0.22';
const PORT_CUBE = 62910;
const CMD_FILE = '/app/data/heiz_cmd.json';
const JSON_OUTPUT = '/app/app/data/heiz_out.json'; // Ajusté selon l'arborescence container

async function syncCube(action = null, rfAddress = null, value = null) {
    return new Promise((resolve) => {
        const maxCube = new MaxCube(IP_CUBE, PORT_CUBE);
        
        maxCube.on('connected', async () => {
            try {
                // Exécution immédiate de l'ordre s'il existe
                if (action === 'temp') {
                    await maxCube.setTemperature(rfAddress, parseFloat(value));
                    await new Promise(r => setTimeout(r, 600));
                } else if (action === 'mode' && value === 'BOOST') {
                    await maxCube.setTemperature(rfAddress, null, 'BOOST');
                    await new Promise(r => setTimeout(r, 600));
                }

                // Extraction des états pour compilation du cache JSON
                const devices = await maxCube.getDeviceStatus();
                const commStatus = maxCube.getCommStatus();

                const dutyVal = commStatus.duty_cycle || 0;
                const output = {
                    system: {
                        duty_val: dutyVal,
                        slots_val: commStatus.free_memory_slots || 50,
                        duty_color: (dutyVal > 80 ? 'var(--red)' : (dutyVal > 50 ? 'var(--orange)' : 'var(--green)'))
                    },
                    devices: {}
                };

                devices.forEach(device => {
                    const deviceInfo = maxCube.getDeviceInfo(device.rf_address) || {};
                    const name = deviceInfo.device_name || '';
                    const room = deviceInfo.room_name || '';

                    if (name.toLowerCase().includes('fensterkontakt') || room.toLowerCase().includes('bbh') || !name.toLowerCase().includes('tm') || !room) {
                        return;
                    }

                    const modeUpper = (device.mode || 'AUTO').toUpperCase();
                    output.devices[device.rf_address] = {
                        id: device.rf_address,
                        room: room.toUpperCase(),
                        mode: modeUpper,
                        soll: parseFloat(device.setpoint) || 20.0,
                        ist: (!device.temp || device.temp === 0) ? "--" : device.temp.toString(),
                        batt: !!device.battery_low,
                        err: (!!device.error || !!device.link_error),
                        boost: (modeUpper === 'BOOST'),
                        win: (parseFloat(device.setpoint) <= 5.1)
                    };
                });

                fs.writeFileSync('/app/data/heiz_out.json', JSON.stringify(output, null, 4));
                maxCube.close();
                resolve(true);
            } catch (e) {
                console.error("❌ Erreur pendant le traitement Max! Cube:", e);
                maxCube.close();
                resolve(false);
            }
        });

        maxCube.on('error', (e) => { 
            console.error("❌ Erreur de connexion au Cube IP:", e.message); 
            resolve(false); 
        });
    });
}

async function run() {
    let lastTelemetry = 0;
    console.log("🐦‍🔥 Démon Thermique A.S.H.E.S. démarré avec succès...");
    
    while (true) {
        const now = Date.now();
        
        // 1. Interception en temps réel des commandes de l'interface Web
        if (fs.existsSync(CMD_FILE)) {
            try {
                const cmd = JSON.parse(fs.readFileSync(CMD_FILE, 'utf8'));
                fs.unlinkSync(CMD_FILE); // Consommation et suppression immédiate du ticket
                console.log(`🚀 Commande reçue : ${cmd.fct} sur la vanne ${cmd.id || 'all'}`);
                await syncCube(cmd.fct, cmd.id, cmd.temp);
                lastTelemetry = Date.now();
            } catch (e) {
                console.error("❌ Échec de parsing de l'ordre reçu:", e);
            }
        }
        
        // 2. Télémétrie périodique automatique toutes les 5 minutes (300000 ms)
        if (now - lastTelemetry > 300000) {
            console.log("🔄 Actualisation périodique de la télémétrie thermique...");
            await syncCube();
            lastTelemetry = Date.now();
        }
        
        // Temporisation de boucle (2 secondes) pour ne pas saturer le processeur
        await new Promise(r => setTimeout(r, 2000));
    }
}

run();