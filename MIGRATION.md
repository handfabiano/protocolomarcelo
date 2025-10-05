# 🔄 Guia de Migração - v2.5.4 → v2.0.0

Este guia irá ajudá-lo a migrar de forma segura para a versão 2.0 do Sistema de Protocolos.

## ⚠️ ANTES DE COMEÇAR

### Pré-requisitos
- ✅ WordPress 5.8 ou superior
- ✅ PHP 7.4 ou superior
- ✅ MySQL 5.7 ou superior
- ✅ Acesso SSH ou FTP ao servidor
- ✅ Backup do banco de dados
- ✅ Backup dos arquivos do plugin

### Tempo Estimado
- **Staging:** 15-30 minutos
- **Produção:** 30-60 minutos

---

## 📋 CHECKLIST PRÉ-MIGRAÇÃO

```bash
[ ] Backup do banco de dados criado
[ ] Backup dos arquivos criados
[ ] Testado em ambiente de staging
[ ] Lida a seção "Breaking Changes" (não há nenhum!)
[ ] Agendada janela de manutenção (se necessário)
[ ] Notificados os usuários (se necessário)
```

---

## 🔧 PASSO A PASSO

### 1️⃣ BACKUP COMPLETO

#### Via WP-CLI (recomendado)
```bash
# Backup do banco
wp db export backup-antes-v2.sql

# Backup dos arquivos
tar -czf backup-plugin-$(date +%Y%m%d).tar.gz wp-content/plugins/protocolo-municipal/
```

#### Via Plugin
Use **UpdraftPlus** ou **BackWPup** para criar backup completo.

#### Via phpMyAdmin
1. Acesse phpMyAdmin
2. Selecione seu banco de dados
3. Clique em "Exportar"
4. Salve o arquivo `.sql`

---

### 2️⃣ DESATIVAR PLUGIN ATUAL

```bash
# Via WP-CLI
wp plugin deactivate protocolo-municipal

# OU via WordPress Admin
# Plugins → Protocolo Municipal → Desativar
```

---

### 3️⃣ ATUALIZAR ARQUIVOS

#### Opção A: Via Git (recomendado)
```bash
cd wp-content/plugins/protocolo-municipal/
git pull origin main
```

#### Opção B: Upload Manual
1. Baixe os novos arquivos
2. Substitua a pasta via FTP/SFTP
3. **NÃO delete** a pasta antiga antes de fazer upload

#### Opção C: Via WordPress Admin
Se você publicou no repositório oficial do WordPress:
```
Plugins → Atualizar → Protocolo Municipal
```

---

### 4️⃣ VERIFICAR NOVOS ARQUIVOS

Certifique-se que estes arquivos existem:

```
wp-content/plugins/protocolo-municipal/
├── src/
│   ├── Performance.php       ← NOVO
│   ├── SLA.php              ← NOVO
│   ├── Notifications.php    ← NOVO
│   ├── Audit.php            ← NOVO
│   └── Workflow.php         ← NOVO
├── assets/
│   └── js/
│       └── dashboard-optimized.js ← ATUALIZADO
└── protocolo-municipal.php  ← ATUALIZADO (v2.0.0)
```

---

### 5️⃣ ATIVAR PLUGIN

```bash
# Via WP-CLI
wp plugin activate protocolo-municipal

# OU via WordPress Admin
# Plugins → Protocolo Municipal → Ativar
```

**O que acontece na ativação:**
- ✅ Tabelas criadas automaticamente:
  - `wp_pmn_notifications`
  - `wp_pmn_audit_log`
  - `wp_pmn_workflows`
  - `wp_pmn_workflow_aprovadores`
- ✅ Índices criados no banco:
  - `idx_pmn_status_tipo`
  - `idx_pmn_data`
  - `idx_pmn_responsavel`
  - `idx_audit_lookup`
- ✅ Crons agendados:
  - `pmn_check_deadlines` (diário às 8h)
  - `pmn_cleanup_old_logs` (mensal)

---

### 6️⃣ VERIFICAR INSTALAÇÃO

#### Via WP-CLI
```bash
# Verifica tabelas criadas
wp db query "SHOW TABLES LIKE 'wp_pmn_%'"

# Deve retornar:
# wp_pmn_audit_log
# wp_pmn_notifications
# wp_pmn_workflow_aprovadores
# wp_pmn_workflows
```

#### Via PHP (adicione temporariamente no tema)
```php
// Verificar instalação
add_action('admin_notices', function() {
    global $wpdb;
    
    $tables = [
        'wp_pmn_notifications',
        'wp_pmn_audit_log', 
        'wp_pmn_workflows',
        'wp_pmn_workflow_aprovadores'
    ];
    
    foreach ($tables as $table) {
        $exists = $wpdb->get_var("SHOW TABLES LIKE '{$table}'");
        echo $exists ? "✅ {$table}<br>" : "❌ {$table} FALTANDO<br>";
    }
});
```

---

### 7️⃣ CONFIGURAR NOVOS MÓDULOS

#### A. Sistema de SLA

**Cadastrar feriados:**
```php
// No wp-admin → Configurações → Protocolos → Feriados
// OU via código (functions.php temporário):

$feriados_2025 = [
    '2025-01-01', // Ano Novo
    '2025-02-24', // Carnaval
    '2025-04-18', // Paixão
    '2025-04-21', // Tiradentes
    '2025-05-01', // Trabalho
    '2025-06-19', // Corpus Christi
    '2025-09-07', // Independência
    '2025-10-12', // N. Sra. Aparecida
    '2025-11-02', // Finados
    '2025-11-15', // Proclamação
    '2025-11-20', // Consciência Negra
    '2025-12-25', // Natal
];

update_option('pmn_feriados', $feriados_2025);
```

**Configurar emails de escalação:**
```php
update_option('pmn_email_supervisor', 'supervisor@prefeitura.gov.br');
update_option('pmn_email_gestor', 'gestor@prefeitura.gov.br');
```

#### B. Sistema de Notificações

**Configurar SMTP (recomendado):**
```php
// Use plugin WP Mail SMTP ou configure manualmente
// Configurações → WP Mail SMTP
```

**Configurar webhook Slack (opcional):**
```php
// Para cada usuário que quiser receber no Slack:
update_user_meta($user_id, 'pmn_webhook_url', 'https://hooks.slack.com/services/XXX/YYY/ZZZ');
```

#### C. Sistema de Workflow

**Configurar regras de aprovação:**
```php
$workflow_rules = [
    'Despacho' => [
        'requer_aprovacao' => true,
        'aprovadores' => [1, 2], // IDs dos aprovadores
        'tipo_fluxo' => 'sequencial',
    ],
    'Relatório' => [
        'requer_aprovacao' => true,
        'valor_minimo' => 1000, // Só valores acima de R$ 1000
        'aprovadores' => [1],
        'tipo_fluxo' => 'unanime',
    ],
];

update_option('pmn_workflow_rules', $workflow_rules);
```

---

### 8️⃣ PROCESSAR PROTOCOLOS EXISTENTES

**Calcular SLA retroativamente:**
```bash
wp eval '
$query = new WP_Query([
    "post_type" => "protocolo",
    "posts_per_page" => -1,
    "post_status" => "publish"
]);

foreach ($query->posts as $post) {
    \ProtocoloMunicipal\SLA::calculate_deadline($post->ID);
    \ProtocoloMunicipal\SLA::update_alert_level($post->ID);
}

echo "✅ " . $query->found_posts . " protocolos processados\n";
'
```

---

### 9️⃣ TESTAR FUNCIONALIDADES

#### Checklist de Testes

```bash
[ ] Dashboard carrega corretamente
[ ] Gráficos são exibidos
[ ] Criar novo protocolo funciona
[ ] Movimentar protocolo funciona
[ ] Editar protocolo funciona
[ ] Notificação de email é enviada
[ ] Badge de notificações aparece
[ ] Timeline carrega
[ ] Exportação CSV funciona
[ ] SLA está calculado nos protocolos
[ ] Alertas de prazo aparecem
[ ] Workflow de aprovação funciona (se configurado)
[ ] Logs de auditoria estão sendo salvos
```

#### Teste de Performance
```bash
# Antes das melhorias
ab -n 100 -c 10 https://seusite.com/lista-de-protocolos/

# Depois das melhorias (deve ser ~70% mais rápido)
```

---

### 🔟 LIMPAR CACHE

```bash
# Cache do WordPress
wp cache flush

# Cache de objeto (Redis/Memcached)
wp cache flush

# Limpar CDN (se usar)
# Cloudflare, etc.
```

---

## 🐛 TROUBLESHOOTING

### Problema: Tabelas não foram criadas

**Solução:**
```php
// Adicione no functions.php e acesse qualquer página do admin
add_action('admin_init', function() {
    \ProtocoloMunicipal\Notifications::create_table();
    \ProtocoloMunicipal\Audit::create_table();
    \ProtocoloMunicipal\Workflow::create_table();
    \ProtocoloMunicipal\Performance::create_indexes();
    echo "Tabelas criadas!";
    exit;
});
```

### Problema: Dashboard não carrega

**Solução:**
```bash
# Limpar cache do navegador (Ctrl + Shift + R)
# Verificar console do navegador (F12)
# Reativar plugin
wp plugin deactivate protocolo-municipal && wp plugin activate protocolo-municipal
```

### Problema: Notificações não enviam

**Solução:**
```bash
# Testar envio de email
wp eval 'wp_mail("seu@email.com", "Teste", "Corpo do email");'

# Se não funcionar, instale WP Mail SMTP
wp plugin install wp-mail-smtp --activate
```

### Problema: Performance não melhorou

**Solução:**
```bash
# Ativar cache de objeto
wp plugin install redis-cache --activate
wp redis enable

# Verificar índices
wp db query "SHOW INDEX FROM wp_postmeta WHERE Key_name LIKE 'idx_pmn%'"
```

---

## 📊 MONITORAMENTO PÓS-MIGRAÇÃO

### Primeira Semana

**Diariamente:**
- ✅ Verificar logs de erro
- ✅ Monitorar performance
- ✅ Verificar se notificações estão sendo enviadas
- ✅ Confirmar que SLA está calculando

```bash
# Ver logs
tail -f wp-content/debug.log

# Ver performance
wp eval 'print_r(\ProtocoloMunicipal\Performance::get_performance_report());'
```

### Primeiro Mês

**Semanalmente:**
- ✅ Revisar relatório de auditoria
- ✅ Verificar taxa de cumprimento de SLA
- ✅ Ajustar regras de workflow (se necessário)

---

## ↩️ ROLLBACK (se necessário)

Se algo der errado:

```bash
# 1. Desativar plugin
wp plugin deactivate protocolo-municipal

# 2. Restaurar backup dos arquivos
cd wp-content/plugins/
rm -rf protocolo-municipal/
tar -xzf backup-plugin-YYYYMMDD.tar.gz

# 3. Restaurar banco (se necessário)
wp db import backup-antes-v2.sql

# 4. Reativar versão antiga
wp plugin activate protocolo-municipal
```

---

## ✅ CONCLUSÃO

Após completar todos os passos:

1. ✅ Remova códigos de teste do `functions.php`
2. ✅ Documente quaisquer personalizações feitas
3. ✅ Atualize documentação interna
4. ✅ Notifique usuários sobre novas funcionalidades
5. ✅ Agende treinamento (se necessário)

---

## 📞 SUPORTE

Se encontrar problemas:

1. Verifique a seção **Troubleshooting** acima
2. Consulte o `CHANGELOG.md`
3. Abra uma issue no GitHub
4. Entre em contato com suporte técnico

---

## 📚 PRÓXIMOS PASSOS

Após migração bem-sucedida:

1. Configure notificações para sua equipe
2. Cadastre feriados do ano
3. Configure regras de workflow (se usar)
4. Treine usuários nas novas funcionalidades
5. Monitore performance e ajuste conforme necessário

**Boa migração! 🚀**
