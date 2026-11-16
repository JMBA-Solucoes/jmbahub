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

Recuperação de sessão validada no **GLPI 11.0.11**. Os metadados aceitam GLPI 10.0.0 a 11.9.99, mas os recursos de formulários nativos dependem do GLPI 11; a versão 10 não foi validada nesta release. GLPI 12 não está incluído no intervalo suportado.

O plugin depende do backend e do aplicativo JMBA Hub para os fluxos móveis e o recebimento das notificações. Esses componentes não fazem parte deste repositório.

## Instalação

1. Baixe `jmbahub-1.4.14.tar.gz` ou `jmbahub-1.4.14.zip` na [release](https://github.com/JMBA-Solucoes/jmbahub/releases/latest).
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

O controle individual é opcional. Desligado, permite o acesso de usuários autenticados no GLPI, conforme seus perfis e entidades. Ligado, exige também habilitação na ficha do usuário ou pelas ações em massa em **Administração → Usuários**. A coluna/filtro **Habilitado para o app (JMBA Hub)** permite consultar os acessos. A atualização preserva a opção configurada na instalação.

### Notificações

Configure a URL base do GLPI e use **Ativar notificações** ou **Verificar e reparar** no painel. A integração envia eventos diretamente ao backend JMBA Hub. O serviço padrão é `https://api-glpi-app.jmbasolucoes.com.br`; o servidor precisa conseguir acessá-lo.

As regras do painel definem quais participantes recebem cada evento. Falhas de conexão são exibidas na configuração e não impedem a instalação do plugin.


### Recuperação da sessão do aplicativo

A versão 1.4.10 integra a recuperação da conexão GLPI com a sessão do JMBA Hub,
permitindo restabelecer a conexão quando a sessão PHP expira, enquanto o acesso
continua autorizado. Exige backend JMBA Hub atualizado e recuperação habilitada.
O plugin usa o formato de sessão REST e o método oficial de leitura da credencial
do GLPI, preservando tokens pessoais existentes e verificando as permissões atuais.

A instalação/atualização tenta preparar a integração automaticamente. **Verificar
e reparar** permite repetir essa preparação se o serviço estiver indisponível.
Use a URL pública HTTPS do GLPI; NPM e outros terminadores TLS podem encaminhar
HTTP na rede interna confiável, sem cadastro manual de IPs de proxy no plugin.
A API continua validando o certificado e o destino público; a rede interna entre
proxy e PHP precisa pertencer à infraestrutura confiável da instalação.

Sessões do aplicativo criadas antes da preparação da recuperação precisam de um
novo login uma vez. A API e o aplicativo são distribuídos separadamente deste plugin.

## Atualização e cache

Substitua os arquivos do plugin, execute a atualização indicada pelo GLPI e limpe o cache. Se o servidor usa `opcache.validate_timestamps=0`, recarregue o PHP-FPM após atualizar os arquivos: o cache do navegador e o cache do GLPI não renovam o código PHP mantido pelo OPcache.

## Desenvolvimento

A raiz deste repositório corresponde ao conteúdo de `plugins/jmbahub`.

```sh
find . -type f -name '*.php' -not -path './.git/*' -exec php -l {} \\;
```

A versão 1.4.10 passou em 34 verificações HTTP integradas no GLPI 11.0.11, incluindo credencial cifrada, identidade, permissões removidas, sessão PHP encerrada e rejeição de credenciais inválidas. O piloto teve 13 verificações pela API de produção e confirmação do usuário de sucesso da validação biométrica no aparelho. O roteiro completo e as evidências técnicas estão no repositório do aplicativo; essa confirmação não representa teste de todos os cenários móveis ou versões GLPI.

## Suporte e licença

- [JMBA Soluções](https://jmbasolucoes.com.br)
- [Relatar um problema](https://github.com/JMBA-Solucoes/jmbahub/issues)
- Licença **GPL-2.0-or-later**. Consulte [LICENSE](LICENSE).


## SSO Nextools no aplicativo — 1.4.14

Em **Configurar → Plugins → JMBA Hub**, ative **Permitir SSO no aplicativo**, selecione explicitamente as aplicações em **Aplicações SSO permitidas no aplicativo** e salve. Uma instalação nova começa com SSO desligado e seleção vazia. O upgrade preserva apenas a seleção anterior. O SSO web do GLPI e as sessões móveis existentes não são desligados por esse controle.

É necessário Nextools ativo, módulo SSO Login carregado e aplicação pronta para login. Zero aplicações autorizadas/prontas mantém o SSO móvel indisponível. Aplicações removidas ou indisponíveis permanecem identificadas no painel. Com várias aplicações disponíveis, o app exige escolha explícita.

Configure `plugin:jmbahub.sso_return_scheme` para o aplicativo instalado: `jmbahub-portfolio` no Portfólio, `jmbahub-jmbasolucoes` no app JMBA Soluções. O painel ainda não possui campo para esse parâmetro. Uma instalação usa um scheme fixo; não copie configurações de outro ambiente.

A integração exige API Java compatível com disponibilidade assinada v2, conclusão assinada v3 e migração V22, além do app com seleção de aplicações e retorno registrado. Atualize o conjunto antes de validar o fluxo. O navegador trata autenticação/MFA no Nextools/IdP; o callback exige sessão autorizada, origem da aplicação escolhida e POST com CSRF. Nenhum token pessoal é criado para SSO e a sessão REST separada permanece no backend.

Atualize a pasta completa `plugins/jmbahub`, faça o upgrade do plugin e limpe o cache GLPI. Se necessário, recarregue PHP-FPM/OPcache. Não desinstale o plugin para atualizar: a desinstalação remove os acessos cadastrados. A ativação e a seleção devem ser conferidas novamente após a atualização.

Testes isolados de política, identidade, callback, início e assinatura estão em `tests/sso-*.php`. Eles não substituem teste integrado de GLPI, IdP/MFA e Android/iOS.
