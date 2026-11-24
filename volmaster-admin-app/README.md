# Volmaster Tech — App Android (equipe / CRM administrativo)

Este é o app nativo do CRM administrativo (`/admin`), empacotado com [Capacitor](https://capacitorjs.com/). Ele **não tem conteúdo próprio** — é uma casca nativa que abre o mesmo site Laravel que já roda em `laravel-crm-2.2/`, dentro de uma WebView. Ou seja: é o mesmo CRM, só que também instalável como app. Abrir a mesma URL num navegador normal continua funcionando exatamente igual.

## Pré-requisitos já verificados nesta máquina

- Node v24 / npm 11 (`node --version`)
- Android SDK em `C:\Users\Volmaster T.I\AppData\Local\Android\Sdk` (`platform-tools`, `build-tools`, `emulator`, `cmdline-tools`)
- JDK 21 (Temurin), instalado via `scoop install temurin21-jdk` — necessário porque o Capacitor 8 / Android Gradle Plugin atual não builda com Java 8 (JDK 17 também não foi suficiente, precisa ser 21).

## A única configuração que muda entre ambientes: `CRM_URL`

Tudo fica em `capacitor.config.js`, na variável `CRM_URL`. Nunca precisa editar código — só passar a variável na hora de sincronizar:

```powershell
$env:CRM_URL = "http://10.0.2.2:8000"; npx cap sync android
```

### 1. Testando no emulador Android (mesma máquina)

1. Subir o Laravel aceitando conexões de fora do `127.0.0.1`:
   ```powershell
   cd ..\laravel-crm-2.2
   php artisan serve --host=0.0.0.0 --port=8000
   ```
2. `10.0.2.2` é o endereço especial que o emulador usa para falar com o `127.0.0.1` da máquina hospedeira — é o valor padrão já configurado, não precisa passar `CRM_URL` explicitamente.
3. Compilar/sincronizar e instalar (veja seção "Build" abaixo).

### 2. Testando num celular físico (mesma rede Wi-Fi)

1. Descobrir o IP local do PC: `ipconfig` (algo como `192.168.0.x`).
2. Subir o Laravel do mesmo jeito: `php artisan serve --host=0.0.0.0 --port=8000`.
3. Liberar a porta 8000 no Firewall do Windows, se necessário.
4. `$env:CRM_URL = "http://192.168.0.x:8000"; npx cap sync android`

### 3. Produção (equipe usando fora da sua rede, ou publicação futura na Play Store)

Isso exige contratar hospedagem com domínio próprio e certificado HTTPS — ainda não configurado. Quando existir:
```powershell
$env:CRM_URL = "https://crm.volmastertech.com.br"; npx cap sync android
```
Nesse ponto, remover o `cleartext: true` deixa de ser necessário automaticamente (o config já detecta `https://` e desliga isso sozinho).

## Build do APK (sem precisar abrir o Android Studio)

```powershell
cd android
$env:JAVA_HOME = "$HOME\scoop\apps\temurin21-jdk\current"
.\gradlew.bat assembleDebug
```

O APK de debug sai em `android\app\build\outputs\apk\debug\app-debug.apk`. Para instalar num emulador ou celular com depuração USB ativada:

```powershell
adb install android\app\build\outputs\apk\debug\app-debug.apk
```

Alternativa: `npx cap open android` abre o projeto no Android Studio, se preferirem builder/depurar por lá.

## Limitações conhecidas (não são bugs)

- **Só Android por enquanto.** Gerar a versão iPhone exige um Mac com Xcode, que não existe neste ambiente Windows.
- **A tela do `/admin` foi feita para desktop.** O Krayin já se adapta bem em tela de celular (menu vira hambúrguer, listas viram cartões), mas não é um redesenho mobile-first — funciona bem, mas não é a experiência mais polida possível.
- **HTTP só serve para testes locais.** Assim que houver hospedagem real com domínio, trocar `CRM_URL` para `https://` e recompilar.
