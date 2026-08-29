# Blind Review Guard — OJS plugin

[![OJS](https://img.shields.io/badge/OJS-3.5-brightgreen)](https://pkp.sfu.ca/ojs/)
[![Version](https://img.shields.io/badge/version-1.0.0.0-blue)](version.xml)
[![License](https://img.shields.io/badge/license-GPL--3.0-lightgrey)](LICENSE)

**⬇️ Install package:** [OJS 3.5](https://github.com/OJSBR/blindReviewGuard/releases/download/1.0.0.0/blindReviewGuard-1.0.0.0.tar.gz) — or browse all [Releases](../../releases).

A generic plugin for **Open Journal Systems (OJS)** that checks the files a reviewer is about
to receive for anything that identifies the authors, and can remove the identifying metadata
automatically. **The file the author uploaded is never touched.**

> **Developed and maintained by [OJSBR](https://ojsbr.com.br).** See the
> [Credits & authorship](#credits--authorship) section below.

## Compatibility & branches

| OJS version | Branch | Plugin release |
|-------------|--------|----------------|
| OJS 3.5.x   | [`stable-3_5_0`](../../tree/stable-3_5_0) *(default)* | 1.0.0.0 |

Requires PHP 8.2+ with the standard `zip` and `mbstring` extensions. No external binary is
needed: there is no dependency on `exiftool`, `qpdf` or `pdftotext`.

## The problem

Anonymous review in OJS rests on a checklist item asking the author to follow the
"Ensuring a Blind Review" instructions. Nothing verifies it. And the leak is almost never in
the part anyone reads:

- **Document properties.** Practically every `.docx` carries the account name of whoever
  created it and whoever last saved it. Practically nobody looks.
- **Tracked changes and comments.** Each mark carries the name of the person who made it, in
  `w:author` attributes spread across `document.xml`, `comments.xml` and `people.xml`.
  Accepting all changes does not remove the comments, and deleting the comments does not
  remove `people.xml`.
- **The body.** The cover page, the corresponding author's e-mail, the funding statement.
- **The file name.** OJS no longer builds the download name from the author's surname, but
  the name the author typed — `Silva - artigo final.docx` — travels with the upload.

The editor usually finds out when a reviewer mentions it: too late, with the anonymity
already broken.

## What it does

- Scans every file **copied into a review stage**, at the moment the copy is created.
- Knows **who the authors of that submission are**. It does not look for "a name": it looks
  for *those* names, e-mail addresses, ORCID iDs and affiliations, read from the submission's
  own contributor list. That is what separates a useful report from a noisy one.
- Reports what it found in the submission's **Activity Log**, and warns the editor on screen.
- Optionally **removes** the identifying metadata from the copy — document properties and the
  author names on tracked changes and comments.
- Stays quiet when the review is **open**: there the author's name is the arrangement, not a
  leak.

### What it never does

- It never touches the file in `SUBMISSION_FILE_SUBMISSION`. Journals that require an
  identified version — with the title page and the full author list — keep it intact for the
  editorial team and for production.
- It never edits the text of a manuscript. A name in the body is reported, never silently
  deleted: rewriting a submission is the author's job and the editor's call.
- It never rewrites a PDF. A PDF is a fragile container and a corrupted manuscript is worse
  than a metadata leak the editor was told about.
- It is **not** a plagiarism or similarity checker, and it cannot guarantee anonymity —
  self-citation and writing style still give an author away. It removes the *mechanical*
  leak, which is the part that can be removed.

## Installation

1. Install via **Settings → Website → Plugins → Upload A New Plugin**, or extract the folder
   into `plugins/generic/` so that you get `plugins/generic/blindReviewGuard/`.
   Do not rename the folder: OJS derives the plugin's class namespace from the directory name.
2. Enable **Blind Review Guard** in the *Generic* plugins list.

## Configuration

Everything is optional and every check can be turned off on its own, under the plugin's
**Settings**:

| Setting | Default | What it means |
|---------|---------|---------------|
| Document properties | on | `dc:creator`, `cp:lastModifiedBy`, `dc:contributor`, `Company`, `Manager` |
| Tracked changes and comments | on | every `w:author` in the package |
| Names, e-mails and ORCID iDs in the text | on | matched against this submission's contributors |
| Author names in the file name | on | the noisiest check; the first one to turn off |
| Remove the metadata automatically | on | Office files only, on the review copy only |
| Warn the editor on screen | on | a notification for whoever is doing the work |
| Check even when the review is open | off | for journals that anonymise regardless |

There is no "block the decision" mode in 1.0.0.0. With automatic cleaning on, the leak is
gone before a reviewer can open the file, and refusing an editor's decision on the strength
of a heuristic is a bigger promise than this plugin should make.

## How it works (technical)

OJS keeps a submission's files in stages, and a reviewer never sees the author's upload. When
an editor sends a submission to review, the core **copies** the selected files into the review
stage — `PKP\decision\steps\PromoteFiles`: *"allows the editor to copy files from one or more
file stages to a new stage"*. The original stays in `SUBMISSION_FILE_SUBMISSION`.

That is why this plugin works on the copy, at the moment the copy is created. Two hooks:

1. **`SubmissionFile::add`** — fires for the copy into `SUBMISSION_FILE_REVIEW_FILE` (4),
   `INTERNAL_REVIEW_FILE` (19) and for the author's revised versions, `REVIEW_REVISION` (15)
   and `INTERNAL_REVIEW_REVISION` (20). Files in any other stage are ignored.
2. **`ReviewAssignment::add`** — the last moment before someone outside the editorial team can
   open the file. Nothing is cleaned here, only reported: rewriting a file under a reviewer's
   feet would be worse than telling the editor about it.

Whether the review is anonymous is read from the journal's `defaultReviewMode` at the first
moment (no reviewer exists yet) and from the assignment's own `reviewMethod` at the second.

Formats, all in pure PHP:

- **OOXML** (`.docx`, `.xlsx`, `.pptx`) via `ZipArchive`: properties, `w:author` attributes,
  and the text — rebuilt from the runs first, because Word routinely splits a name across a
  dozen `<w:t>` elements and a search over the raw XML would miss it.
- **PDF**: the `/Info` dictionary (literal and UTF-16 hex strings) and the XMP packet. The
  body is read on a best-effort basis by inflating the FlateDecode content streams; when that
  fails — a scanned PDF, an exotic encoding — the report says the text could not be read
  rather than reporting the file as clean.

Cleaning works on a copy of the package and only then moves it over the original, so an
interrupted run cannot leave a truncated manuscript behind.

## Tests

The suite covers the parts that decide whether the report is trustworthy: boundary matching
(`Sousa` must not fire on `Sousada`), names split across runs, accented text, the neutral
placeholders Word writes, the fact that cleaning removes the metadata and leaves the
manuscript alone, and the PDF scanner admitting when it could not read the body.

Fixtures are **generated, not committed**: a reviewer can read exactly what makes each file
dirty, and the repository stays free of opaque binaries.

The suite is written for PHPUnit and is collected by PKP's `ApplicationPlugins` suite. Because
the OJS release tarball ships no development dependencies, it also runs standalone:

```bash
php plugins/generic/blindReviewGuard/tests/run.php
```

```
42 passed, 0 failed
```

## Credits & authorship

- **Developed and maintained by** [OJSBR](https://ojsbr.com.br) — original plugin.
- Distributed under the **GNU GPL v3**, the same license as OJS.

## Contributing

Issues and pull requests are welcome. Translations are handled through PKP's
[Weblate](https://translate.pkp.sfu.ca/projects/plugins/); entries marked `fuzzy` are the ones
still waiting for a native speaker.

## License

Distributed under the **GNU GPL v3**. See [`LICENSE`](LICENSE) and `docs/COPYING`.

---

## 🇧🇷 Português

Plugin genérico para o **Open Journal Systems (OJS)** que verifica os arquivos que o avaliador
está prestes a receber em busca de qualquer coisa que identifique os autores, e pode remover
automaticamente os metadados identificadores. **O arquivo enviado pelo autor nunca é alterado.**

> **Desenvolvido e mantido pela [OJSBR](https://ojsbr.com.br).**

### Compatibilidade e branches

| Versão do OJS | Branch | Release do plugin |
|---------------|--------|-------------------|
| OJS 3.5.x     | [`stable-3_5_0`](../../tree/stable-3_5_0) *(padrão)* | 1.0.0.0 |

Requer PHP 8.2+ com as extensões `zip` e `mbstring`. Não depende de nenhum binário externo —
nada de `exiftool`, `qpdf` ou `pdftotext`.

### O problema

A avaliação cega no OJS se apoia num item de checklist pedindo ao autor que siga as instruções
de "Garantindo a avaliação cega". Nada confere. E o vazamento quase nunca está onde alguém
olha: nas **propriedades do documento** (praticamente todo `.docx` carrega o nome da conta de
quem criou e de quem salvou por último), nas **marcas de revisão e comentários** (cada marca
leva o nome de quem a fez, em `w:author` espalhados por `document.xml`, `comments.xml` e
`people.xml`), no **corpo do texto** (folha de rosto, e-mail do autor correspondente,
agradecimentos) e no **nome do arquivo**.

O editor costuma descobrir quando o avaliador comenta: tarde, com a cegueira já quebrada.

### O que faz

- Verifica todo arquivo **copiado para um estágio de avaliação**, no instante em que a cópia
  é criada.
- **Sabe quem são os autores daquela submissão.** Não procura "um nome": procura *aqueles*
  nomes, e-mails, iDs ORCID e afiliações, lidos da própria lista de contribuidores. É isso que
  separa um laudo útil de um laudo que o editor aprende a ignorar.
- Registra o que encontrou no **Histórico de Atividades** da submissão e avisa o editor na tela.
- Opcionalmente **remove** os metadados identificadores da cópia.
- Fica calado quando a avaliação é **aberta**.

### O que nunca faz

- Nunca toca no arquivo do estágio de submissão. Revistas que exigem a versão identificada —
  com folha de rosto e lista completa de autores — a mantêm intacta.
- Nunca edita o texto do manuscrito. Nome no corpo é relatado, jamais apagado em silêncio.
- Nunca reescreve um PDF.
- **Não** é antiplágio nem Similarity Check, e não garante anonimato: autocitação e estilo
  continuam entregando o autor. Ele elimina o vazamento *mecânico*, que é o que dá para eliminar.

### Instalação

Instale em **Configurações → Website → Plugins → Enviar um novo plugin**, ou extraia a pasta
em `plugins/generic/` (ficando `plugins/generic/blindReviewGuard/`). Não renomeie a pasta: o
OJS deriva o namespace da classe do nome do diretório. Depois ative o **Blind Review Guard**
na lista de plugins *Genéricos*.

### Configuração

Cada verificação pode ser desligada isoladamente nas **Configurações** do plugin: propriedades
do documento, marcas de revisão e comentários, nomes/e-mails/ORCID no texto, nome do arquivo,
limpeza automática, aviso na tela e verificar mesmo em avaliação aberta. Os padrões deixam
tudo ligado, exceto o último.

Não existe modo "bloquear a decisão" na 1.0.0.0: com a limpeza automática ligada o vazamento
some antes de qualquer avaliador abrir o arquivo, e recusar a decisão de um editor com base
numa heurística é uma promessa maior do que este plugin deve fazer.

### Testes

A suíte cobre o que decide se o laudo é confiável: casamento por limite de palavra (`Sousa`
não pode disparar em `Sousada`), nome partido entre runs, texto acentuado, os valores neutros
que o Word escreve, a limpeza que remove o metadado e preserva o manuscrito, e o scanner de
PDF admitindo quando não conseguiu ler o corpo. Os arquivos de teste são **gerados, não
versionados**.

```bash
php plugins/generic/blindReviewGuard/tests/run.php
```

### Créditos e autoria

- **Desenvolvido e mantido pela** [OJSBR](https://ojsbr.com.br) — plugin autoral.
- Distribuído sob a **GNU GPL v3**, a mesma licença do OJS.

### Licença

Distribuído sob a **GNU GPL v3**. Veja [`LICENSE`](LICENSE) e `docs/COPYING`.
