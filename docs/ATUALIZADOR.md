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
   novamente" em Painel, Atualizações limpa o cache. A versão só vale com
   tag `vX.Y.Z`, publicada (não rascunho nem pré-versão) e os três arquivos
   (`rastro-vX.Y.Z.zip`, `.sha256`, `.sig`) enviados, cada um uma vez, no
   endereço `https://github.com/samuelmarmelo/rastro/releases/download/<tag>/<arquivo>`
   e dentro do limite (zip até 5 MB; `.sha256` e `.sig` até 1 KB). O
   WordPress compara com a versão instalada e mostra a atualização.
2. **Download** (`upgrader_pre_download`, só para o pacote com a marca
   `#rastro-assinado`): lê a versão de novo (se mudou desde a oferta, para),
   recusa versão igual ou menor que a instalada, e baixa os três arquivos.
   Cada arquivo: pedido ao endereço público sem seguir redirecionamento; o
   `Location` precisa ser https, sem usuário, senha ou porta diferente de
   443, num host `<nome>.githubusercontent.com`; o destino é baixado também
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
   `rastro/rastro.php`): pacote que não passou pela conferência é recusado;
   a pasta extraída vira `rastro/` e o cabeçalho precisa ter
   `Plugin Name: Rastro` e a `Version` conferida.
5. **Automático** (`auto_update_plugin`): o Rastro se atualiza sozinho no
   ciclo do WordPress. Para desligar, no `wp-config.php`:

   ```php
   define( 'RASTRO_ATUALIZAR_AUTO', false );
   ```

6. **Detalhes** (`plugins_api`, slug `rastro`): versão, requisitos e as
   notas da versão escapadas. Outro diretório nunca responde por este slug.

## 3. Ameaças e respostas

| Ameaça | Resposta |
|---|---|
| Arquivo trocado no caminho ou no armazenamento | SHA-256 do `.sha256`, `digest` da API e tamanho |
| Conta do repositório comprometida (versão falsa publicada) | assinatura Ed25519: a chave privada fica só na máquina de quem publica, fora do repositório |
| Assinatura de uma versão reaproveitada em outra | a mensagem assinada leva a tag e o nome do arquivo |
| Volta para versão antiga | só versão maior que a instalada; tags e versões publicadas imutáveis no repositório |
| Redirecionamento para host estranho ou rede interna | prefixo exato, `Location` conferido, `wp_safe_remote_get` sem seguir redirecionamento |
| Pacote enorme | limites antes e durante o download |
| Vazamento de dado do site | sem token, sem endereço do site no User-Agent |
| Notas da versão com HTML | escapadas no modal |

## 4. Textos da tela

Os textos falam "servidor de atualizações", nunca o nome da plataforma, e
todos dizem "Nada foi instalado" quando recusam.
