#!/usr/bin/env python3
"""Gera contrato/vetores-hash-v2.json a partir de verificador/hash_v2.py. Rodar da raiz."""
import json,sys
sys.path.insert(0,'verificador'); import hash_v2 as h
CASOS=[
 ("projeção: campo fora de campos_hash não pesa", {"campos_hash":["a"]}, '{"a":1,"rastro":"x"}', 100),
 ("projeção: campos_hash vazio, só o valor", {"campos_hash":[]}, '{"a":1}', 5000000),
 ("projeção: campo de campos_hash ausente no payload", {"campos_hash":["a","b"]}, '{"a":1}', None),
 ("nulo no primeiro nível = ausente", {"campos_hash":["a","b"]}, '{"a":1,"b":null}', None),
 ("nulo aninhado em objeto é removido", {"campos_hash":["o"]}, '{"o":{"x":null,"y":2}}', None),
 ("nulo dentro de array fica", {"campos_hash":["l"]}, '{"l":[1,null,2]}', None),
 ("texto: espaços, \\t, \\n e \\r internos viram um espaço", {"campos_hash":["t"]}, '{"t":"a  b\\tc\\n\\r\\nd"}', None),
 ("texto: pontas sem espaço", {"campos_hash":["t"]}, '{"t":"  \\t abc \\n"}', None),
 ("texto: NFC (e + U+0301 vira é)", {"campos_hash":["t"]}, '{"t":"cafe\\u0301"}', None),
 ("texto: NBSP (U+00A0) não é espaço da regra", {"campos_hash":["t"]}, '{"t":"a\\u00a0b"}', None),
 ("texto: só espaços vira texto vazio", {"campos_hash":["t"]}, '{"t":"   "}', None),
 ("texto aninhado em array e objeto também normaliza", {"campos_hash":["l"]}, '{"l":[{"n":" x  y "}]}', None),
 ("chaves de objeto não são normalizadas", {"campos_hash":["o"]}, '{"o":{" k ":1}}', None),
 ("ordenar_arrays por um campo", {"campos_hash":["p"],"ordenar_arrays":{"p":["socio"]}}, '{"p":[{"socio":"S2","v":1},{"socio":"S1","v":2}]}', None),
 ("ordenar_arrays por dois campos, ausente antes de qualquer valor", {"campos_hash":["p"],"ordenar_arrays":{"p":["socio","tributo"]}}, '{"p":[{"socio":"S1","tributo":"PIS"},{"socio":"S1"},{"socio":"S1","tributo":"COFINS"}]}', None),
 ("ordenar_arrays compara o texto canônico (número 10 antes de 9 como texto)", {"campos_hash":["p"],"ordenar_arrays":{"p":["n"]}}, '{"p":[{"n":9},{"n":10}]}', None),
 ("ordenar_arrays: empate desempata pelo item inteiro", {"campos_hash":["p"],"ordenar_arrays":{"p":["socio"]}}, '{"p":[{"socio":"S1","v":2},{"socio":"S1","v":1}]}', None),
 ("ordenar_arrays: normaliza antes de ordenar", {"campos_hash":["p"],"ordenar_arrays":{"p":["socio"]}}, '{"p":[{"socio":" S2"},{"socio":"S1 "}]}', None),
 ("array fora de ordenar_arrays mantém a ordem", {"campos_hash":["l"]}, '{"l":["b","a"]}', None),
 ("valor_centavos sempre entra, mesmo nulo", {"campos_hash":["a"]}, '{}', None),
 ("ordem das chaves no canônico por code point, como na v4", {"campos_hash":["b","a","é"]}, '{"é":1,"b":2,"a":3}', 7),
]
saida=[]
for nome,ct,payload,valor in CASOS:
    p=json.loads(payload); e=h.entrada_hash_v2(p,valor,ct)
    saida.append({"nome":nome,"contrato":{"campos_hash":ct["campos_hash"],"ordenar_arrays":ct.get("ordenar_arrays",{})},
                  "payload":payload,"valor_centavos":valor,"canonico":e,"sha256":h.hash_v2(p,valor,ct)})
# pares que TÊM de dar o mesmo hash (o que o C19 cobra)
pares=[
 ("ordem diferente das parcelas", {"campos_hash":["parcelas"],"ordenar_arrays":{"parcelas":["socio","tributo"]}}, '{"parcelas":[{"socio":"S1","valor_centavos":1},{"socio":"S2","valor_centavos":2}]}', '{"parcelas":[{"socio":"S2","valor_centavos":2},{"socio":"S1","valor_centavos":1}]}'),
 ("rastro diferente", {"campos_hash":["competencia_impressa","vencimento"]}, '{"competencia_impressa":"202609","vencimento":"2026-10-20","documento_id":"doc-1"}', '{"competencia_impressa":"202609","vencimento":"2026-10-20","documento_id":"doc-2"}'),
 ("nulo × ausente", {"campos_hash":["inss_centavos","irrf_centavos"]}, '{"inss_centavos":1}', '{"inss_centavos":1,"irrf_centavos":null}'),
 ("NFC e espaços", {"campos_hash":["texto"]}, '{"texto":"Revis\\u00e3o  feita\\npor Ana"}', '{"texto":"Revisa\\u0303o feita por Ana "}'),
]
iguais=[]
for nome,ct,a,b in pares:
    ha=h.hash_v2(json.loads(a),None,ct); hb=h.hash_v2(json.loads(b),None,ct); assert ha==hb,nome
    iguais.append({"nome":nome,"contrato":{"campos_hash":ct["campos_hash"],"ordenar_arrays":ct.get("ordenar_arrays",{})},"payload_a":a,"payload_b":b,"valor_centavos":None,"sha256":ha})
doc={"descricao":"Vetores do hash v2 (semântica v5 §6.3). hash = sha256(canonico({payload: ordenar(normalizar(projetar(payload))), valor_centavos})). 'payload' é o texto JSON bruto; 'contrato' traz só campos_hash e ordenar_arrays; 'canonico' é a entrada exata do sha256 (UTF-8). 'iguais': pares que têm de dar o mesmo hash. A etapa canônica é a da v4 e continua coberta por vetores-hash.json. Gerado por verificador/hash_v2.py; não editar à mão.",
     "casos":saida,"iguais":iguais}
open('contrato/vetores-hash-v2.json','w').write(json.dumps(doc,ensure_ascii=False,indent=2)+"\n")
print(len(saida),len(iguais))
for x in saida[:9]: print(x['nome'],'|',x['canonico'])
