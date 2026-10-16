# Aiutoma Dev

The official developer companion plugin for **Aiutoma**.

## 🚀 Overview

This extension unlocks power-user and developer abilities inside Aiutoma:
- **Execute PHP Code (`aiutoma/execute-php`)**: Run arbitrary PHP code snippets within WordPress with interactive CodeMirror editing, automatic change recording, and 1-click rollbacks.
- **Modify Files (`aiutoma/modify-file`)**: Create or edit files inside `wp-content` with automatic physical file backups.
- **Run WP-CLI (`aiutoma/run-wp-cli`)**: Execute WP-CLI commands synchronously directly from your AI agent.

## 🛡️ Security Architecture

To keep the core **Aiutoma** plugin 100% compliant with WordPress.org repository guidelines (which strictly disallow arbitrary `eval()` and direct code execution in directory plugins), all developer and sandbox execution capabilities are cleanly housed in this standalone extension.

Activating or deactivating this extension serves as the master switch for all developer abilities across the Playground, REST API, and MCP tools.

## 📋 Requirements
- WordPress 6.0+
- PHP 8.1+
- [Aiutoma](https://wordpress.org/plugins/aiutoma/) core plugin

