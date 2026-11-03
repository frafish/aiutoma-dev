---
name: release-aiutoma-dev
description: >-
  Istruzioni operative per preparare, taggare e pubblicare nuove versioni del plugin WordPress
  Aiutoma Dev su GitHub, garantendo che l'autoupdate di WordPress scarichi il pacchetto completo.
---

# Guida al Rilascio di Aiutoma Dev (Autoupdate GitHub)

Questa skill descrive passo dopo passo come eseguire i rilasci di nuove versioni per il plugin **Aiutoma Dev** (`aiutoma-dev`).

Il plugin utilizza la libreria **Plugin Update Checker (PUC)** per verificare la presenza di nuove release su GitHub ([`frafish/aiutoma-dev`](https://github.com/frafish/aiutoma-dev)) e scaricare l'archivio compilato `aiutoma-dev.zip` generato automaticamente dalla GitHub Action.

---

## 1. Prerequisiti prima del rilascio

1. **Assicurarsi che la working directory sia pulita**:
   ```bash
   git status
   ```
2. **Verificare che non vi siano errori di sintassi PHP**:
   ```bash
   php -l aiutoma-dev.php
   ```
3. **Verificare le dipendenze Composer**:
   ```bash
   composer validate --no-check-all
   ```

---

## 2. Procedura di Rilascio Passo-Passo

### Passo 1: Incrementare la versione
Apri il file principale `aiutoma-dev.php` e aggiorna la versione in **due** punti:
1. Nell'intestazione del plugin:
   ```php
   * Version:           1.0.1
   ```
2. Nella costante PHP:
   ```php
   define('AIUTOMA_DEV_VERSION', '1.0.1');
   ```

*(Opzionale)* Se presente, aggiorna anche la sezione Changelog nel file `README.md`.

---

### Passo 2: Commit e Push delle modifiche
Esegui il commit delle modifiche di versione e pusha sul branch principale (`main`):
```bash
git add aiutoma-dev.php README.md composer.json composer.lock
git commit -m "chore: bump version to 1.0.1"
git push origin main
```

---

### Passo 3: Creare e Pushare il Git Tag
Crea un tag con prefisso `v` (o solo numero) corrispondente alla versione appena impostata:
```bash
# Esempio per la versione 1.0.1
git tag v1.0.1
git push origin v1.0.1
```

> [!IMPORTANT]
> È il comando `git push origin v<versione>` che fa scattare l'automazione CI/CD di GitHub Actions!

---

### Passo 4: Monitoraggio della GitHub Action
1. Vai su GitHub alla scheda **Actions** del repository:
   `https://github.com/frafish/aiutoma-dev/actions`
2. Vedrai il workflow **Build & Release Package** in esecuzione.
3. Il workflow eseguirà:
   - `composer install --no-dev --optimize-autoloader`
   - Creazione dello zip `aiutoma-dev.zip` contenente il plugin pronto e la cartella `vendor/`
   - Pubblicazione della GitHub Release associata al tag con l'asset allegato.
4. Quando il workflow è verde (Success), la nuova versione è ufficialmente disponibile.

---

## 3. Come verificare l'aggiornamento in WordPress

WordPress controlla gli aggiornamenti periodicamente (ogni 12 ore circa tramite transient). Per testare o forzare subito il controllo:

1. **Da interfaccia WordPress**:
   - Vai in **Bacheca > Aggiornamenti** (`wp-admin/update-core.php`).
   - Clicca sul pulsante **"Verifica di nuovo"**.
   - Nella lista dei plugin troverai **Aiutoma Dev** con la notifica:
     > *"È disponibile una nuova versione di Aiutoma Dev 1.0.1. Visualizza dettagli versione 1.0.1 oppure aggiorna adesso."*
   - Cliccando su **"Aggiorna adesso"**, WordPress scaricherà `aiutoma-dev.zip`, sovrascriverà i file e aggiornerà il plugin mantenendo intatte tutte le dipendenze di `vendor/`.

2. **Da terminale / WP-CLI** (se disponibile):
   ```bash
   wp transient delete update_plugins
   wp plugin update aiutoma-dev
   ```

---

## 4. Risoluzione Problemi Comuni

- **La GitHub Action fallisce con errore 403 / Resource not accessible by integration**:
  - Vai nelle impostazioni del repository GitHub: **Settings > Actions > General > Workflow permissions**.
  - Seleziona **"Read and write permissions"** e salva.
- **WordPress segnala un aggiornamento ma il download fallisce**:
  - Controlla nella pagina della Release su GitHub (`https://github.com/frafish/aiutoma-dev/releases/latest`) che sia effettivamente presente l'asset `aiutoma-dev.zip`.
- **Dopo l'aggiornamento mancano librerie in `vendor/`**:
  - Assicurati che lo zip allegato alla release sia stato generato dalla GitHub Action e non sia il generico `Source code (zip)` generato in automatico da GitHub (che esclude i file in `.gitignore`). PUC è configurato specificamente per scaricare l'asset della Release grazie a `$aiutoma_dev_updater->getVcsApi()->enableReleaseAssets()`.
