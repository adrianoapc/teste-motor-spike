# Diagramas do motor do fechamento (semântica v3)

**07/10/2026.** Desenhos de consulta. A fonte normativa continua sendo `docs/semantica-do-motor.md` e as migrações em `db/migrations/`. Se divergirem, vale o texto e o SQL.

## 1 · Estados do entregável e o que vem depois do protocolo

```mermaid
stateDiagram-v2
    direction TB
    [*] --> aguardando_insumo: abrir_competencia
    aguardando_insumo --> pronto: dependências e entradas presentes
    pronto --> processado: sistema processa ou tarefa concluída
    processado --> validado: conferências ok
    processado --> divergente: conferência B divergente
    divergente --> validado: override com motivo
    validado --> liberado: liberação automática (E11)
    validado --> encerrado: E12 (encerra o caso)
    liberado --> disponibilizado: protocolo (App ou e-mail)

    pronto --> invalidado: fato de entrada mudou
    processado --> invalidado: fato de entrada mudou
    divergente --> invalidado: fato de entrada mudou
    validado --> invalidado: fato de entrada mudou
    liberado --> invalidado: fato de entrada mudou
    invalidado --> pronto: entradas presentes
    invalidado --> aguardando_insumo: falta entrada

    disponibilizado --> [*]
    encerrado --> [*]

    note right of disponibilizado
        Estado final do E11 (protocolo).
        Depois dele, nada transiciona.
        Mudança de conteúdo: exceção substituicao.
    end note
    note right of encerrado
        Só o E12. Exige E11 em disponibilizado
        e os demais em validado (regra v2).
    end note
```

**Depois do protocolo (§10.3 e §15), sem reabrir o caso e sem transição:**

```mermaid
flowchart LR
    P["E11 disponibilizado<br/>(protocolo)"] --> E12["E12 encerra o caso<br/>fechamento.concluido"]
    P --> ENT["Entrega ao cliente<br/>canal, leitura, lembrete"]
    ENT -->|entrega_falhou| X1["Exceção entrega_falhou<br/>dono = carteira"]
    ENT -->|recebimento_pendente<br/>agendador, N dias| X2["Exceção recebimento_pendente"]
    ENT -->|reenvio| R["Novo protocolo<br/>resolve entrega_falhou"]
    ENT -->|entrega_confirmada| C["recebimento.confirmado<br/>resolve as exceções"]
    ENT -->|guia.entregue| REG["Regularidade fiscal<br/>checa pagamento (CF-17)"]
```

- Estado `pago` aposentado (V006). Pagamento é da Regularidade fiscal.
- Caso encerrado não volta: fato novo vira a exceção `competencia_encerrada` (§11.3).

## 2 · Tabelas (colunas principais)

Ligações marcadas como FK existem no banco. As de `conferencia`, `excecao`, `tarefa_humana`, `evento` e `entregavel_fato.fato_id` são **lógicas** (só o id), porque cada módulo tem o seu esquema (ADR-014). `entregavel_transicao` e `fatos.fato` são só de inserção.

Fora do desenho: `regra_caso_ouro`, `calendario`, `transicao_permitida`, `eventos.fila`, `eventos.entrega_evento` e as views `indicadores.i1`–`i6`.

```mermaid
erDiagram
  REGRA ||--o{ REGRA_VERSAO : versiona
  REGRA_VERSAO ||--o{ CASO_COMPETENCIA : "regra em uso"
  CASO_COMPETENCIA ||--o{ ENTREGAVEL : tem
  ENTREGAVEL_TIPO ||--o{ ENTREGAVEL : classifica
  ESTADO_ENTREGAVEL ||--o{ ENTREGAVEL : "estado atual"
  ENTREGAVEL ||--o{ ENTREGAVEL_TRANSICAO : historico
  ENTREGAVEL ||--o{ ENTREGAVEL_DEPENDENCIA : "depende de"
  ENTREGAVEL ||--o{ ENTREGAVEL_FATO : usa
  FATO ||--o{ ENTREGAVEL_FATO : "entrada ou saida"
  FATO |o--o| FATO : substitui
  ENTREGAVEL ||--o{ CONFERENCIA : confere
  CONFERENCIA_TIPO ||--o{ CONFERENCIA : "CF-xx"
  ENTREGAVEL |o--o{ EXCECAO : abre
  CASO_COMPETENCIA ||--o{ EXCECAO : "sem entregavel"
  ENTREGAVEL ||--o{ TAREFA_HUMANA : gera
  CASO_COMPETENCIA ||--o{ EVENTO : outbox
  REGRA {
    text chave PK
  }
  REGRA_VERSAO {
    uuid id PK
    text regra_chave FK
    int versao
    jsonb conteudo
    text status
  }
  CASO_COMPETENCIA {
    uuid id PK
    text titular_id
    char competencia
    text carteira
    uuid regra_entregaveis_versao_id FK
    text estado
  }
  ENTREGAVEL {
    uuid id PK
    uuid caso_id FK
    text tipo FK
    text estado FK
    text executor
    int version
  }
  ENTREGAVEL_TIPO {
    text chave PK
    text area
    text evento_publicado
  }
  ESTADO_ENTREGAVEL {
    text estado PK
    int ordem
    bool final
    bool aposentado
  }
  ENTREGAVEL_TRANSICAO {
    bigint id PK
    uuid entregavel_id FK
    text de_estado
    text para_estado
    text motivo
    text causado_por_tipo
  }
  ENTREGAVEL_DEPENDENCIA {
    uuid entregavel_id FK
    uuid depende_de_id FK
    text estado_minimo
  }
  ENTREGAVEL_FATO {
    uuid entregavel_id FK
    uuid fato_id
    text papel
  }
  FATO {
    uuid id PK
    text titular_id
    char competencia
    text tipo
    text tributo
    int versao
    bigint valor_centavos
    text hash
    uuid substitui_id FK
  }
  CONFERENCIA {
    uuid id PK
    text cf FK
    uuid entregavel_id
    jsonb fatos_usados
    text entrada_hash
    text resultado
    text severidade
    text override_autor
  }
  CONFERENCIA_TIPO {
    text chave PK
    text entregavel_tipo
    text severidade
    bool no_spike
  }
  EXCECAO {
    uuid id PK
    uuid caso_id
    uuid entregavel_id
    text tipo
    text classe
    text estado
    text resolucao
  }
  TAREFA_HUMANA {
    uuid id PK
    uuid entregavel_id
    text responsavel
    text estado
  }
  EVENTO {
    uuid id PK
    text nome
    uuid caso_id
    uuid entregavel_id
    jsonb payload
  }
```
