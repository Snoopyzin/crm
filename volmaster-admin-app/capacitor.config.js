// A URL do CRM fica em um único lugar, trocável sem mexer em código:
//   CRM_URL=http://10.0.2.2:8000 npx cap sync android        (emulador Android)
//   CRM_URL=http://192.168.x.x:8000 npx cap sync android      (celular físico, mesma Wi-Fi)
//   CRM_URL=https://crm.volmastertech.com.br npx cap sync android  (produção, quando existir)
const crmUrl = process.env.CRM_URL || 'http://10.0.2.2:8000';

/** @type {import('@capacitor/cli').CapacitorConfig} */
const config = {
  appId: 'com.volmastertech.admin',
  appName: 'Volmaster Tech',
  webDir: 'www',
  server: {
    url: crmUrl,
    // Necessário apenas enquanto o CRM roda em HTTP (dev/testes locais).
    // Remover assim que houver um domínio com HTTPS de verdade em produção.
    cleartext: crmUrl.startsWith('http://'),
  },
};

module.exports = config;
