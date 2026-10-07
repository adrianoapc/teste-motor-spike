# Build
FROM mcr.microsoft.com/dotnet/sdk:10.0 AS build
WORKDIR /src
COPY Directory.Build.props ./
COPY Motor.sln ./
COPY src/ ./src/
RUN dotnet restore src/Motor.Api/Motor.Api.csproj
RUN dotnet publish src/Motor.Api/Motor.Api.csproj -c Release -o /app --no-restore

# Runtime
FROM mcr.microsoft.com/dotnet/aspnet:10.0 AS runtime
WORKDIR /app
COPY --from=build /app ./
ENV ASPNETCORE_URLS=http://+:8080
ENV DOTNET_CLI_TELEMETRY_OPTOUT=1
EXPOSE 8080
ENTRYPOINT ["dotnet", "Motor.Api.dll"]
