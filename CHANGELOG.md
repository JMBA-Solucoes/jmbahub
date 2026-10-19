# Histórico de alterações

## 1.4.10 — 2026-10-19

- Recuperação da conexão GLPI integrada à sessão do JMBA Hub, com credencial exclusiva por instalação.
- Compatibilidade com tokens REST cifrados das versões recentes do GLPI, sem converter sessões REST em sessões de navegador.
- Leitura do token pessoal cifrado pelo método oficial do GLPI; reutilização de tokens existentes e criação somente quando ausentes e permitida.
- Verificação de identidade, usuário ativo e autorização atual de perfil, entidade e recursividade; contexto removido exige nova seleção.
- Funcionamento com HTTPS público terminado no NPM e HTTP interno, sem configuração manual de IPs de proxy no plugin.
- Provisionamento na instalação/atualização, incluindo instalações migradas, preservando identidade e configurações.
- Correção do bloqueio transacional no driver GLPI e classificação específica de falhas, sem exposição de credenciais nos avisos do core.
- Preservação da configuração opcional de controle individual de acesso e das regras de notificação.

Validação: 34 verificações integradas no GLPI 11.0.11, 13 verificações pela API de produção e confirmação do usuário de sucesso da biometria no aparelho. GLPI 10 teve verificações isoladas de compatibilidade; não foi validado em uma instalação real nesta release. GLPI 12 não é suportado.

Atualize o pacote completo e o cache do GLPI. Recarregue PHP-FPM/container se o OPcache não verifica mudanças nos arquivos. A recuperação depende de API atualizada/habilitada; sessões abertas antes da preparação exigem um novo login uma vez.

## 1.4.0

- Identidade JMBA Hub e autoria JMBA Soluções.
- Identificador do plugin, namespaces e rotas migrados para `jmbahub`.
- Logo no catálogo de plugins, nas configurações e no menu lateral.
- Migração dos acessos e configurações existentes em `glpiapp`.
- Controle de acesso ao aplicativo e notificações configuráveis por evento e destinatário.
- Integração com catálogo de serviços e formulários nativos do GLPI 11.

Validado por instalação e ativação no GLPI 11.0.8, verificação da migração de dados, sintaxe PHP e respostas de autenticação das rotas. Os fluxos móveis completos dependem do backend JMBA Hub.
