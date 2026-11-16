# Atualizador do Rastro

Contrato de `rastro/atualizacao/atualizador.php` e
`rastro/atualizacao/chaves.php`. Formato das versões: `docs/RELEASE.md`.
Testes: `tests/validate-atualizador-contract.js` e
`tests/php/atualizador.test.php`.

## 1. Quando age

Só no plugin próprio: `plugin_basename( RASTRO_ARQUIVO ) === 'rastro/rastro.php'`.
Numa cópia embutida (tema ou plugin de site que traz a pasta `rastro/`),
nenhum gancho é registrado: quem embute atualiza a cópia. Com o plugin
próprio ativo, a cópia embutida nem carrega (guarda `RASTRO_VERSAO`).

O cabeçalho `Update URI: https://github.com/samuelmarmelo/rastro` faz o
WordPress não procurar atualização de outro plugin com o slug `rastro` e
chamar o filtro `update_plugins_github.com` para este plugin.

## 2. Passo a passo

1. **Oferta** (`update_plugins_github.com`): lê
   `api.github.com/repos/samuelmarmelo/rastro/releases/latest`, sem token,
   com User-Agent `Rastro/<versão>; WordPress` (sem o endereço do site), sem
   seguir redirecionamento. Cache de 6 h (erro: 30 min); "Verificar
   novamente" em Painel, Atualizações limpa o cache. A leitura anônima tem
   limite por IP: quando ela falha, a última leitura boa continua valendo
   por mais 30 min. Sem a extensão sodium (nem o sodium_compat do
   WordPress), nada é oferecido. A versão só vale com
   tag `vX.Y.Z`, publicada (não rascunho nem pré-versão) e os três arquivos
   (`rastro-vX.Y.Z.zip`, `.sha256`, `.sig`) enviados, cada um uma vez, no
   endereço `https://github.com/samuelmarmelo/rastro/releases/download/<tag>/<arquivo>`
   e dentro do limite (zip até 5 MB; `.sha256` e `.sig` até 1 KB). O
   WordPress compara com a versão instalada e mostra a atualização.
2. **Download** (`upgrader_pre_download`, só para o pacote com a marca
   `#rastro-assinado`): apaga qualquer conferência anterior, lê a versão de
   novo (vale a leitura boa dos últimos 10 min; se mudou desde a oferta, para),
   recusa versão igual ou menor que a instalada, e baixa os três arquivos.
   Cada arquivo: pedido ao endereço público sem seguir redirecionamento; o
   `Location` precisa ser https, sem usuário, senha ou porta diferente de
   443, num dos hosts de arquivo de versão (`release-assets`, `objects` ou
   `github-releases` `.githubusercontent.com`); o destino é baixado também
   sem seguir redirecionamento, com limite de tamanho.
3. **Conferência**, nesta ordem (qualquer falha apaga o temporário e nada é
   instalado):
   - tamanho igual ao publicado;
   - SHA-256 igual ao do `.sha256` (nome do arquivo conferido) e ao
     `digest` da API, quando ela informar;
   - assinatura Ed25519 do `.sig` sobre a mensagem `rastro-release-v1`
     (tag, nome e SHA-256) com uma das chaves de `chaves.php`. Sem a
     extensão sodium, usa o sodium_compat do WordPress; sem nenhum dos
     dois, recusa.
4. **Pasta** (`upgrader_source_selection`, só na atualização de
   `rastro/rastro.php`): pacote que não passou pela conferência é recusado,
   e a conferência vale para uma pasta só (é apagada ao ser usada);
   a pasta extraída vira `rastro/` e o cabeçalho precisa ter
   `Plugin Name: Rastro` e a `Version` conferida.
5. **Automático** (`auto_update_plugin`): na primeira vez no painel, o
   Rastro entra na lista de atualização automática do site; depois vale a
   escolha de quem administra (link "Desativar atualizações automáticas" na
   tela Plugins), e o Rastro nunca a desfaz. Mesmo ligada, a atualização
   automática só instala versão publicada há 48 h ou mais: janela para tirar
   do ar uma versão ruim antes que os sites a recebam sozinhos. O botão
   "Atualizar agora" não espera. Para desligar de vez, no `wp-config.php`:

   ```php
   define( 'RASTRO_ATUALIZAR_AUTO', false );
   ```

6. **Detalhes** (`plugins_api`, slug `rastro`): versão, requisitos e as
   notas da versão escapadas. Outro diretório nunca responde por este slug.

## 3. Ameaças e respostas

| Ameaça | Resposta |
|---|---|
| Arquivo trocado no caminho ou no armazenamento | SHA-256 do `.sha256`, `digest` da API e tamanho |
| Conta do repositório comprometida (versão falsa publicada) | assinatura Ed25519: a chave privada fica só na máquina de quem publica, fora do repositório e de pasta sincronizada, com senha |
| Chave privada vazada | atualização automática espera 48 h: dá tempo de apagar a versão e trocar a chave (docs/RELEASE.md, seção 3) |
| Assinatura de uma versão reaproveitada em outra | a mensagem assinada leva a tag e o nome do arquivo |
| Volta para versão antiga | só versão maior que a instalada; tags e versões publicadas imutáveis no repositório |
| Redirecionamento para host estranho ou rede interna | prefixo exato, `Location` conferido, `wp_safe_remote_get` sem seguir redirecionamento |
| Pacote enorme | limites antes e durante o download |
| Conferência reaproveitada por outro pacote na mesma requisição | a conferência vale uma vez e é apagada no começo de todo download |
| Limite da leitura anônima (hospedagem compartilhada) | leitura boa recente reaproveitada; falha mantém a última leitura boa |
| Vazamento de dado do site | sem token, sem endereço do site no User-Agent |
| Notas da versão com HTML | escapadas no modal |

## 4. Textos da tela

Os textos falam "servidor de atualizações", nunca o nome da plataforma, e
todos dizem "Nada foi instalado" quando recusam.

## 5. Da cópia embutida para o plugin próprio

`rastro/atualizacao/instalador.php` só carrega no painel e só numa cópia
embutida. Na tela Plugins e nas telas do Rastro, para quem pode ativar
plugins, aparece o aviso "Rastro embutido neste site" com o botão:

- **Instalar o Rastro** (plugin ausente; precisa de `install_plugins` e
  `activate_plugins`): lê a última versão, baixa e confere pelo mesmo
  caminho do atualizador (seção 2, passos 2 e 3), instala com o
  `Plugin_Upgrader` a partir do arquivo local (pasta `rastro/`, nome e
  versão conferidos) e ativa.
- **Ativar o Rastro** (instalado e desativado; `activate_plugins`).

Outros avisos da cópia embutida:

- **Plugin próprio ativo, mas parado**: o plugin `rastro/rastro.php` está
  ativo, mas uma cópia embutida carregou antes (um plugin cuja pasta vem
  antes na ordem de carga e inclui o Rastro cedo). O aviso pede para
  desativar quem embute ou carregar a cópia só sem o plugin próprio.
- **Pasta ocupada**: `rastro/rastro.php` existe, mas não é o Rastro. Sem
  botão; o plugin não pode ser instalado ali.

O clique vai por `admin-post` com nonce próprio (`rastro_instalar_plugin`) e
volta para Plugins com um código curto (texto fixo, nada vindo da URL vira
texto). Nada é instalado sem o clique. Na página seguinte o plugin próprio
carrega primeiro e a cópia embutida fica parada; as opções do Rastro são as
mesmas e nada se perde.

Para o site que embute: depois do plugin próprio ativo, a cópia embutida
pode continuar no pacote como reserva (só roda se o plugin próprio for
desativado) e deve vir sempre de uma versão publicada, sem edição local.
