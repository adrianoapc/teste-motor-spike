# Spike Python — imagem única, ouve na porta 8080 do contêiner.
FROM python:3.13-slim

ENV PYTHONUNBUFFERED=1 \
    PIP_NO_CACHE_DIR=1 \
    PIP_DISABLE_PIP_VERSION_CHECK=1

WORKDIR /app

# Instala só as dependências de runtime primeiro (cache de camada).
COPY pyproject.toml ./
COPY src ./src
RUN pip install .

# A conexão vem só de MOTOR_DB (injetada pelo compose). Nada no código/imagem.
EXPOSE 8080
CMD ["uvicorn", "motor.api.app:app", "--host", "0.0.0.0", "--port", "8080"]
