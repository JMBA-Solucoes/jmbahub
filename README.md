<p align="center"><img src="logo.svg" width="112" alt="Logo JMBA Hub"></p>

# JMBA Hub para GLPI

Integração do GLPI com o aplicativo **JMBA Hub**, desenvolvida pela **JMBA Soluções**. Centraliza o controle de acesso ao aplicativo, as notificações de chamados e a integração com formulários nativos do GLPI 11.

## Recursos

- Controle de acesso ao aplicativo por usuário, com filtro e ações em massa na lista de usuários.
- Notificações de criação de chamados, atribuição de técnicos, acompanhamentos, tarefas, mudanças de status, soluções e fechamento.
- Seleção dos destinatários de cada evento: requerentes, observadores e técnicos.
- Integração com catálogo de serviços e formulários nativos: estrutura, envio de respostas, anexos e busca de participantes e itens.
- Painel de configuração com identidade visual JMBA Hub.
- Migração dos acessos e das configurações do plugin anterior `glpiapp`.

## Compatibilidade

Validado no **GLPI 11.0.8**. Os metadados aceitam GLPI 10.0.0 a 11.9.99, mas os recursos de formulários nativos dependem do GLPI 11; a versão 10 não foi validada nesta release. GLPI 12 não está incluído no intervalo suportado.

O plugin depende do backend e do aplicativo JMBA Hub para os fluxos móveis e o recebimento das notificações. Esses componentes não fazem parte deste repositório.

## Instalação

1. Baixe `jmbahub-1.4.0.tar.gz` ou `jmbahub-1.4.0.zip` na [release](https://github.com/JMBA-Solucoes/jmbahub/releases/latest).
2. Extraia o pacote no diretório `plugins` da instalação GLPI. O resultado deve ser `plugins/jmbahub/setup.php`.
3. Ajuste as permissões para permitir a leitura pelo usuário do servidor web.
4. Em **Configurar → Plugins**, instale e ative **JMBA Hub**.
5. Abra a configuração do plugin para ajustar os módulos e as notificações.

**A pasta instalada deve se chamar `jmbahub`, sem hífen.** O GLPI exige identificadores alfanuméricos. Prefira os pacotes anexados à release, que já contêm a pasta correta.

Também é possível instalar pelo console, a partir da raiz do GLPI, executando como o usuário do servidor web:

```sh
php bin/console plugin:install jmbahub --no-interaction
php bin/console plugin:activate jmbahub --no-interaction
php bin/console cache:clear --no-interaction
```

## Configuração

O painel está disponível em **Configurar → Plugins → JMBA Hub**. Com o controle de acesso ligado, o menu **Administração → JMBA Hub** também oferece acesso ao painel.

### Acesso ao aplicativo

Habilite o módulo de controle de acesso e gerencie as permissões na ficha do usuário ou pelas ações em massa em **Administração → Usuários**. A coluna/filtro **Habilitado para o app (JMBA Hub)** permite consultar os acessos.

### Notificações

Configure a URL base do GLPI e use **Ativar notificações** ou **Verificar e reparar** no painel. A integração envia eventos diretamente ao backend JMBA Hub. O serviço padrão é `https://api-glpi-app.jmbasolucoes.com.br`; o servidor precisa conseguir acessá-lo.

As regras do painel definem quais participantes recebem cada evento. Falhas de conexão são exibidas na configuração e não impedem a instalação do plugin.


## Atualização e cache

Substitua os arquivos do plugin, execute a atualização indicada pelo GLPI e limpe o cache. Se o servidor usa `opcache.validate_timestamps=0`, recarregue o PHP-FPM após atualizar os arquivos: o cache do navegador e o cache do GLPI não renovam o código PHP mantido pelo OPcache.

## Desenvolvimento

A raiz deste repositório corresponde ao conteúdo de `plugins/jmbahub`.

```sh
find . -type f -name '*.php' -not -path './.git/*' -exec php -l {} \\;
```

A validação de sintaxe não substitui os testes de integração em uma instalação GLPI. A release 1.4.0 foi instalada e ativada no GLPI 11.0.8, com conferência da migração de configurações e acessos e da rejeição de chamadas sem autenticação.

## Suporte e licença

- [JMBA Soluções](https://jmbasolucoes.com.br)
- [Relatar um problema](https://github.com/JMBA-Solucoes/jmbahub/issues)
- Licença **GPL-2.0-or-later**. Consulte [LICENSE](LICENSE).
