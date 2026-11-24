# Cena do caminhão na rua (link de acompanhamento do cliente)

O link que o cliente recebe mostra o caminhão dele (na cor do cadastro) andando numa rua
até a oficina. Cada oficina que comprar o CRM pode ter **a foto da própria fachada colada
na parede da oficina dentro dessa cena**.

## Como a oficina cadastra a fachada

No CRM: **Configuração → Geral → Identidade da oficina**

- **Nome da oficina**: aparece no letreiro, na bandeira, no pilar e no topo do link.
- **Foto da fachada**: JPG, PNG ou WEBP (até 6 MB), de preferência na horizontal.

Assim que a foto é salva, o link do cliente já mostra **a fachada com o caminhão dele chegando
na frente**. Isso funciona sozinho, sem nenhum passo da Volmaster.

## Cena 3D completa (a foto colada no prédio da rua animada)

É a versão "insana": a rua em 3D, com a fachada real da oficina no prédio. Ela precisa ser
**gerada uma vez por oficina**, no computador da Volmaster (precisa de Node, Chromium do Playwright
e ffmpeg; não roda na hospedagem do cliente):

```bash
cd promo/caminhao
node gerar-hero.js --fachada /caminho/foto-fachada.jpg --nome "Auto Center Silva" \
  --saida "../../volmaster crm/public/img/oficinas/<ID-DA-OFICINA>"
```

- `<ID-DA-OFICINA>` é o id da oficina no sistema (ou `principal` numa instalação única).
- Cada cor leva cerca de 1 min e meio; as 15 cores, cerca de 25 min. Para só algumas cores, liste-as
  no fim do comando (ex.: `branco vermelho azul`).
- Envie a pasta `public/img/oficinas/<ID-DA-OFICINA>/` para o servidor da oficina. A página passa a
  usar esses arquivos automaticamente; se uma cor não existir ali, usa a foto da fachada com o caminhão.

## Prioridade que a página usa

1. Cena 3D personalizada da oficina (`public/img/oficinas/<id>/rua-<cor>.webp` + `chegou-<cor>.jpg`)
2. Foto da fachada cadastrada + caminhão do cliente chegando na frente
3. Cena padrão da Volmaster (só quando a oficina não cadastrou nome nem fachada)

## Outros scripts

- `gerar-sprites.js`: caminhão 3D recortado (PNG) nas 15 cores (`public/img/caminhoes/caminhao-<cor>.png`).
- `renderizar.js`: GIF/MP4 da cena (estrada ou rua) para divulgação.
