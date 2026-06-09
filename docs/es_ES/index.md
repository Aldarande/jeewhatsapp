# JeeWhatsApp — Documentación

> ID del plugin : `jeewhatsapp`  
> Autor : Aldarande — Licencia : AGPL v3

---

## Instalación

### Requisitos previos

| Componente | Versión | ¿Obligatorio? |
|---|---|---|
| Jeedom | ≥ 4.4.0 | ✅ |
| Node.js | ≥ 18 | ✅ (ya presente en las cajas Jeedom recientes) |
| `ffmpeg` | cualquiera | ⚠️ opcional (notas de voz/TTS/STT) — instalado automáticamente |
| `python3` + `pip3` | 3.8+ | ⚠️ opcional (STT Vosk) — ya presente en Jeedom |
| `tesseract-ocr` | 4+ | ⚠️ opcional (OCR de imágenes) — instalado automáticamente |

> Compatibilidad de hardware : x86_64 (VM/PC/NUC/Docker) y **ARM** (Raspberry Pi, cajas Jeedom
> Smart/Atlas/Luna). El binario de Piper se obtiene via `uname -m` para la arquitectura destino.

### Pasos

1. **Instalar el plugin** a través del market de Jeedom (o subiendo manualmente el zip) y luego **activarlo**.
2. **Instalar las dependencias** : botón « Instalar las dependencias » en la página del plugin —
   esto instala Baileys + ffmpeg + Piper (TTS) + Tesseract (OCR) + Vosk (STT) de una sola vez.
   - Contar **5 a 15 minutos** según la conexión y el equipo (descargas ~150 MB).
   - Los componentes de IA son **no bloqueantes** : si uno falla, el plugin funciona sin esa función.
3. **Crear un equipo** JeeWhatsApp (cualquier nombre), luego **guardarlo**.
4. **Iniciar el demonio** : Jeedom lo lanza automáticamente tras la creación del equipo.
   En caso de problema, reinícielo manualmente mediante el botón « Iniciar el demonio ».
5. **Escanear el QR code** : abra el equipo → pestaña **Configuración** → aparece un QR code.
   En su teléfono : WhatsApp → **Ajustes → Dispositivos vinculados → Vincular un dispositivo**,
   luego escanee. El estado pasa a **« conectado »** en 5 segundos.
6. **Crear o buscar el grupo canal** : botón « Crear » en la sección *Grupo vinculado*
   (crea un grupo vacío llamado `jeewhatsapp` por defecto), o « Buscar » si ya lo ha
   creado manualmente. Luego añada sus contactos a ese grupo de WhatsApp.
7. **Probar** : pestaña **Test** → botón « Enviar al grupo canal ». Debe recibir
   `🏠 Test JeeWhatsApp 🚀` en el grupo.

### Actualización

El plugin gestiona la actualización automáticamente mediante `jeewhatsapp_update()` (creación de los crons faltantes
si los hay nuevos). Tras una actualización mayor, vuelva a lanzar **« Instalar las dependencias »** si
se anuncian nuevos componentes en el changelog.

### Desinstalación

La desinstalación **conserva** la carpeta `resources/jeewhatsappd/auth/` (sesiones de WhatsApp)
para evitar tener que volver a escanear el QR tras una reinstalación. Para borrar todo :
elimine esta carpeta manualmente tras la desinstalación.

---

## Presentación

JeeWhatsApp integra **WhatsApp** en Jeedom a través de [Baileys](https://github.com/WhiskeySockets/Baileys),
una biblioteca open-source que se conecta directamente a WhatsApp Web.
**Ningún dato pasa por un servidor de terceros** — todo permanece entre su servidor Jeedom y los servidores de WhatsApp.

### Principio de funcionamiento — el grupo canal

JeeWhatsApp se basa en un **grupo de WhatsApp dedicado** que sirve como canal de comunicación bidireccional entre Jeedom y el usuario.

- **Jeedom → usted** : cada mensaje enviado desde un escenario llega al grupo, con un prefijo (ej. : `🏠 `)
- **Usted → Jeedom** : sus mensajes en el grupo son recibidos por Jeedom y activan sus escenarios o el motor de interacciones
- Los mensajes fuera del grupo (mensajes directos, otros grupos) son ignorados

> Este grupo puede ser creado automáticamente por el plugin desde la interfaz de configuración.

### Funcionalidades

- 💬 **Envío de mensajes** desde un escenario de Jeedom al grupo canal
- 📥 **Recepción en tiempo real** — WebSocket persistente, sin polling
- 🔄 **Bidireccional** — controle Jeedom a través de WhatsApp desde el grupo
- 🤖 **Interacciones Jeedom** — respuestas automáticas a través del motor de interacciones integrado
- 📱 **Su número** — conexión por QR code, no se requiere número dedicado
- 🔒 **100 % self-hosted** — sin cuentas de terceros, sin clave API, sin suscripción
- ⚡ **Tiempo real** — conexión WebSocket persistente, recepción instantánea

### Lo que el plugin no hace (actualmente)

- Envío de medios (imágenes, vídeos, documentos, notas de voz)
- Envío a un número no registrado en WhatsApp
- Recepción de mensajes fuera del grupo canal
- **Notificaciones push para el propietario de la cuenta** (véase más abajo)

### ⚠️ Limitación — notificaciones push

JeeWhatsApp usa **su propia cuenta de WhatsApp** (vinculada por QR code).
WhatsApp nunca notifica al propietario de la cuenta por los mensajes que él mismo envía —
esta regla también se aplica a las menciones (`@usted`), que han sido probadas y tampoco generan notificación.

**Consecuencia :** cuando Jeedom publica en el grupo canal, usted **no recibe ninguna notificación push** en su teléfono.
Puede ver los mensajes abriendo el grupo, pero no hay alerta de sonido ni banner.

**Solución : usar una segunda cuenta de WhatsApp dedicada a Jeedom**

| Configuración | Notificaciones | Complejidad |
|---|---|---|
| Cuenta única (su número) | ❌ Ninguna para usted | Simple |
| Dos cuentas (bot Jeedom + su número) | ✅ Notificaciones reales | Requiere un 2.º número |

Con dos cuentas :
- La cuenta "bot Jeedom" (número virtual o SIM dedicada) está conectada en Jeedom
- Esta cuenta envía los mensajes al grupo
- Su cuenta personal es **miembro del grupo** y recibe las notificaciones normalmente
- El comando "Enviar un mensaje" en un escenario notifica su teléfono como cualquier mensaje de grupo

> Un número virtual (Google Voice, número eSIM de bajo coste) es suficiente — no necesita estar activo permanentemente para WhatsApp.

---

## Requisitos previos

- Jeedom 4.4 o superior
- Node.js **18 o superior** en la máquina Jeedom
- Un teléfono con la aplicación WhatsApp para escanear el QR code

---

## Instalación

### Paso 1 — Instalar el plugin

Copie la carpeta `jeewhatsapp` en `plugins/` de su instalación de Jeedom,
luego vaya a **Plugins → Gestión de plugins** y active **JeeWhatsApp**.

### Paso 2 — Instalar las dependencias

En la página del plugin, haga clic en **Instalar las dependencias**.
El script instala `@whiskeysockets/baileys`, `qrcode` y `pino` vía npm.
Este paso puede tardar **2 a 5 minutos** según su conexión a internet.

> **Requisito Node.js**  
> El script verifica la versión de Node.js. Si es inferior a 18, la instalación falla.  
> Para verificar : `node --version` en un terminal.

### Paso 3 — Iniciar el demonio

Haga clic en **Iniciar el demonio** en la página de configuración del plugin.
El estado debe pasar a **OK**.

---

## Configuración

### Crear un equipo

Vaya a **Plugins → Comunicación → JeeWhatsApp** y haga clic en **Añadir**.

| Campo | Descripción |
|---|---|
| Nombre | Nombre mostrado en Jeedom (ej. : Mi WhatsApp) |
| Objeto padre | Objeto Jeedom al que asociar el equipo |
| Activar | Debe estar marcado para que el demonio tenga en cuenta este equipo |
| **Grupo canal** | Nombre exacto del grupo de WhatsApp usado como canal (por defecto : `jeewhatsapp`) |
| **Grupo vinculado** | JID del grupo rellenado automáticamente tras búsqueda o creación — solo lectura |
| Interacciones Jeedom | Activa las respuestas automáticas a través del motor de interacciones |
| **Indicador "escribiendo"** | (v0.3) Muestra `escribiendo…` / `grabando…` durante ~1 s antes de cada envío automático. Humaniza los mensajes. |
| **Mensajes efímeros** | (v0.3) Desactivado / 24 h / 7 d / 90 d. Todos los mensajes enviados por Jeedom desaparecen automáticamente tras el tiempo elegido. |
| **Prefijo Jeedom** | Texto añadido al inicio de cada mensaje enviado por Jeedom (por defecto : `🏠 `) |

Guarde. Los comandos se crean automáticamente.

### Configurar el grupo canal

Tras guardar el equipo, debe vincular un grupo de WhatsApp.
**Primero debe estar conectado a WhatsApp (QR code escaneado).**

Dos opciones en el campo **Grupo canal** :

**Opción A — Grupo existente**

1. Cree manualmente un grupo de WhatsApp desde su teléfono y nómbrelo (ej. : `jeewhatsapp`)
2. Indique este nombre en el campo **Grupo canal**
3. Haga clic en **Buscar** — el JID se rellena automáticamente
4. Guarde

**Opción B — Crear el grupo desde Jeedom**

1. Indique el nombre deseado en **Grupo canal**
2. Haga clic en **Crear** — el grupo se crea en WhatsApp y el JID se rellena
3. Guarde
4. Desde su teléfono, añada los miembros deseados al grupo

> **El campo "Grupo vinculado" (JID)**  
> Este campo de solo lectura contiene el identificador técnico del grupo de WhatsApp (formato `120363…@g.us`).
> Se rellena automáticamente mediante los botones **Buscar** / **Crear** y no debe modificarse manualmente.

---

## Conexión por QR code (primera conexión)

Tras crear y guardar el equipo, vaya a la pestaña **Conexión WhatsApp**.

1. Un QR code se muestra automáticamente (actualización cada 8 segundos)
2. Abra WhatsApp en su teléfono
3. Vaya a **Ajustes → Dispositivos vinculados → Vincular un dispositivo**
4. Escanee el QR code
5. El estado pasa a **Conectado** ✅

> **Sesión persistente**  
> Una vez conectado, las credenciales se guardan localmente en `resources/jeewhatsappd/auth/{id}/`.
> No tendrá que volver a escanear el QR code en cada reinicio del demonio.

> **Búsqueda automática del grupo**  
> En cuanto se establece la conexión con WhatsApp, el demonio busca automáticamente el grupo canal configurado.
> El resultado se muestra en los logs `jeewhatsapp` : `✓ Grupo "jeewhatsapp" → 120363…@g.us`.

---

## Comandos

### Comandos INFO (lectura)

| Nombre | logicalId | Subtipo | Historizado | Descripción |
|---|---|---|---|---|
| Último mensaje | `last_message` | string | no | Texto del último mensaje recibido en el grupo canal |
| Remitente | `last_sender` | string | no | Número del remitente del último mensaje |
| Nombre del remitente | `last_sender_name` | string | no | Apodo de WhatsApp del remitente |
| Recibido el | `last_received_at` | string | no | Marca de tiempo del último mensaje recibido |
| Enviados (hora actual) | `sent_hour` | numeric | sí | Contador de envíos durante la hora en curso (reset cada hora) |
| Recibidos hoy | `messages_today` | numeric | sí | Contador de mensajes recibidos desde medianoche (reset cron daily 00:02) |
| Conectado desde | `connected_since` | string | no | Fecha/hora de la última conexión de WhatsApp Web (refresh cron 5 min) |
| Última reacción | `last_reaction` | string | no | Emoji de la última reacción recibida en el grupo (vacío = reacción eliminada) |
| Reacción — remitente | `last_reaction_from` | string | no | Número del autor de la última reacción |
| Reacción — fecha | `last_reaction_at` | string | no | Marca de tiempo de la última reacción |
| Último medio — ruta | `last_attachment_path` | string | no | Ruta absoluta del servidor del último medio recibido (imagen/vídeo/audio/documento/sticker) |
| Último medio — tipo | `last_attachment_type` | string | no | `image` / `video` / `audio` / `document` / `sticker` |
| Último medio — mime | `last_attachment_mime` | string | no | Tipo MIME del último medio recibido (`image/jpeg`, `audio/ogg; codecs=opus`, ...) |
| Último medio — tamaño | `last_attachment_size` | numeric | no | Tamaño en bytes del último medio recibido |
| Encuesta — pregunta | `poll_question` | string | no | Pregunta de la última encuesta cuyo voto se recibió |
| Encuesta — resultados | `poll_results` | string | no | Resultados en formato JSON `[{name, votes}]` |
| Encuesta — total votos | `poll_total` | numeric | sí | Número total de votos recibidos en la última encuesta |
| Último grupo — etiqueta | `last_group` | string | no | (v0.3) Etiqueta del grupo de origen del último mensaje recibido (vacío = grupo canal principal) |
| Último grupo — nombre | `last_group_name` | string | no | (v0.3) Nombre del grupo de WhatsApp de origen del último mensaje recibido |

### Comandos ACCIÓN

| Nombre | logicalId | Subtipo | Descripción |
|---|---|---|---|
| Enviar un mensaje | `send_message` | message | Envía un mensaje al grupo canal. Campo **Título** = destinatario opcional (vacío = grupo canal, de lo contrario número directo). |
| Responder | `reply` | message | Respuesta "citada" al último mensaje recibido en el grupo (cita visible). |
| Enviar un medio | `send_media` | message | Envía un archivo (imagen, vídeo, audio, documento). Campo **Título** = ruta absoluta, **Mensaje** = leyenda opcional. |
| Enviar una ubicación | `send_location` | message | Envía una posición GPS. Campo **Título** = `lat\|long` o `lat\|long\|nombre`. |
| Enviar un contacto | `send_contact` | message | Envía una tarjeta vCard. Campo **Título** = número, **Mensaje** = nombre mostrado (opcional). |
| Reaccionar al último mensaje | `react_last` | message | Envía una reacción emoji al último mensaje recibido. Campo **Mensaje** = emoji (❤️ 👍 🎉 …) o vacío para eliminar la reacción. |
| Editar el último mensaje | `edit_last` | message | (v0.3) Reemplaza el texto del último mensaje **enviado** por Jeedom. Campo **Mensaje** = nuevo texto. |
| Eliminar el último mensaje | `revoke_last` | other | (v0.3) Elimina "para todos" el último mensaje **enviado** por Jeedom (botón, sin parámetros). |
| Reenviar el último mensaje recibido | `forward_to` | message | (v0.3) Reenvía el último mensaje **recibido** a un destinatario. Campo **Título** = destinatario opcional (vacío = grupo canal). |
| Enviar un sticker | `send_sticker` | message | (v0.3) Envía un sticker. Campo **Título** = ruta absoluta de un `.webp` (o `.png`/`.jpg` convertido en WebP 512×512). |
| Enviar una encuesta | `send_poll` | message | (v0.3) Envía una encuesta. Campo **Título** = pregunta, **Mensaje** = opciones separadas por `\|` (ej. : `Sí\|No\|Quizás`, 2 a 12 opciones). Los votos alimentan los comandos info `poll_*`. |
| Enviar en un grupo adicional | `send_group` | message | (v0.3) Envía un mensaje en un grupo adicional. Campo **Título** = etiqueta del grupo (ver config « Grupos adicionales »), **Mensaje** = texto. |

> **💡 Campo "Título" del comando Enviar un mensaje**  
> Jeedom muestra dos campos para los comandos de tipo `message` : **Título** y **Mensaje**.  
> En JeeWhatsApp, el campo **Título** es un **override opcional** :  
> — Vacío → el mensaje se envía al **grupo canal**  
> — Número (ej. : `33612345678`) → envío directo a ese número (fuera del grupo)  
> — JID de grupo (ej. : `120363…@g.us`) → envío a ese grupo específico

> **💡 Comando Responder**  
> En modo grupo canal, `reply` envía al grupo (visible para todos los miembros).
> La respuesta no es privada — es un mensaje público en el canal.

> **💡 Prefijo Jeedom**  
> Todos los mensajes enviados por Jeedom se prefijan automáticamente (ej. : `🏠 `).
> Los miembros del grupo pueden así distinguir las alertas de Jeedom de sus propios mensajes.
> El demonio ignora los mensajes `fromMe` en el grupo, evitando que Jeedom procese sus propios envíos.

> **📍 Enviar una ubicación (`send_location`)**  
> Formato del campo **Título** : `lat|long` o `lat|long|nombre` (separador `|`).  
> Ejemplos :
> - `48.8566|2.3522` → Torre Eiffel sin etiqueta
> - `48.8566|2.3522|Tour Eiffel` → con nombre del lugar
> - `45.7640|4.8357|Place Bellecour, Lyon` → con dirección
>
> Validación : lat ∈ [-90, 90], long ∈ [-180, 180]. El campo **Mensaje** se ignora.

> **👤 Enviar un contacto (`send_contact`)**  
> Formato del campo **Título** : número internacional sin `+` ni espacios (ej. : `33612345678`).  
> Formato español aceptado : `0612345678` (convertido automáticamente a `33612345678`).  
> Campo **Mensaje** = nombre mostrado de la vCard (opcional, de lo contrario se usa el número).

> **👥 Grupos adicionales (`send_group`) — v0.3**  
> Por defecto, un equipo solo escucha y escribe en **un** grupo canal. Para gestionar
> varios grupos (alertas, información, familia…) con la **misma** cuenta de WhatsApp, indique el
> campo **Grupos adicionales** del equipo, una línea por grupo en el formato
> `etiqueta=Nombre exacto del grupo WhatsApp` :
> ```
> alertas=Alertas Casa
> familia=Grupo Familia
> ```
> - La **etiqueta** (`alertas`, `familia`…) sirve para apuntar al grupo mediante el comando
>   **Enviar en un grupo adicional** (`send_group`) : campo **Título** = etiqueta, **Mensaje** = texto.
> - Los mensajes **recibidos** en estos grupos también alimentan los comandos info,
>   y el grupo de origen se expone mediante `last_group` (etiqueta, vacío = grupo principal) y `last_group_name`.
> - El grupo canal **principal** permanece sin cambios ; esta función es puramente aditiva
>   (retrocompatible con las configuraciones existentes).

---

## Interacciones Jeedom

Cuando la opción **Interacciones Jeedom** está activada, cada mensaje recibido en el grupo canal
se transmite al motor de interacciones de Jeedom. Si una interacción coincide, la respuesta se
envía automáticamente **en el grupo canal**.

### Filtro por palabra clave activadora (v0.2)

Campo **« Palabra clave activadora »** en la configuración del equipo. Si se indica, solo los mensajes
que **comiencen** por esta palabra clave (insensible a mayúsculas/minúsculas) activan las interacciones. La palabra clave se
**elimina** del mensaje antes de transmitirlo al motor de interacciones de Jeedom — permite tener
formulaciones naturales en Jeedom evitando el ruido en el grupo.

| Configuración | Mensaje recibido | Comportamiento |
|---|---|---|
| keyword vacío | `enciende salon` | → interactQuery busca `enciende salon` |
| keyword = `!jeedom` | `hola familia` | → ignorado (log debug) |
| keyword = `!jeedom` | `!jeedom enciende salon` | → interactQuery busca `enciende salon` |
| keyword = `@jeedom` | `@JEEDOM estado` | → interactQuery busca `estado` (mayúsculas ignoradas) |

### Lista blanca de remitentes (v0.2 — seguridad)

Campo **« Lista blanca de remitentes »** : si se indica, solo los números listados pueden activar
las interacciones de Jeedom. Los demás miembros del grupo son **ignorados silenciosamente** (log debug).

**Formato aceptado** : 1 número por línea o separados por coma, en cualquier formato :
- `0612345678` (español corto)
- `33612345678` (internacional)
- `+33 6 12 34 56 78` (con espacios y +)

Todos los formatos se normalizan al formato internacional antes de la comparación.

> **🛡️ Seguridad** : la lista blanca protege contra un miembro malintencionado que se una al grupo e intente
> enviar comandos de Jeedom. Combinada con el filtro de palabra clave, ofrece una doble capa de protección.

Ejemplos de interacciones configurables en Jeedom :

| Mensaje recibido | Respuesta automática |
|---|---|
| `temperatura salón` | `La temperatura del salón es de 21°C` |
| `enciende la luz` | `Luz encendida` |
| `estado` | `Todos los equipos están OK` |

> Configure sus interacciones en **Herramientas → Interacciones** en Jeedom.

### Comandos shortcuts — « slash » (v0.4)

Campo **« Comandos shortcuts »** : atajos rápidos activados por un mensaje
que comienza por `/`. Son **prioritarios** sobre el motor de interacciones (NLP) y no
requieren ninguna configuración en *Herramientas → Interacciones* — ideal para comandos
frecuentes.

**Formato** : una línea por atajo, `/activador=destino`. El destino puede ser :

| Tipo de destino | Ejemplo de línea | Efecto del mensaje `/activador` |
|---|---|---|
| **Comando acción** `#id#` | `/escena=#9012#` | Ejecuta el comando acción `9012`, responde `✅ Nombre del comando` |
| **Comando info** `#id#` | `/temp=#1234#` | Responde el valor actual : `Temperatura salón : 21 °C` |
| **Texto modelo** | `/hola=Hola #args# !` | `/hola Pablo` → responde `Hola Pablo !` |
| **Modelo + tags Jeedom** | `/casa=Salón #1234# / Ext #5678#` | Reemplaza los `#id#` de infos por su valor |

**Variables disponibles en un texto modelo** :
- `#args#` : todos los argumentos tras el activador (`/echo hola mundo` → `#args#` = `hola mundo`)
- `#1#`, `#2#`, … : cada palabra de argumento por separado

Para un comando acción de subtipo *message*, el argumento se pasa como texto del
mensaje ; para un *slider*, como valor ; para un *color*, como código de color.

Un activador desconocido devuelve `❓ Atajo desconocido : /xxx`.

> **Ejemplo completo** :
> ```
> /salon=#1234#
> /encender=#1057#
> /estado=🏠 Salón : #1234# °C — Alarma : #1099#
> /di=Mensaje recibido : #args#
> ```
> Luego en el grupo : `/salon` → `Temperatura salón : 21 °C`, `/di Hola` → `Mensaje recibido : Hola`.

---

### Reconocimiento de usuario (v0.4)

Campo **« Reconocimiento de usuario »** : asocia el número de un remitente a un **perfil
de Jeedom**. Una línea por correspondencia, en el formato `número=perfil`.

```
33612345678=Papá
0698765432=Mamá
33700000000=Hijo
```

Los números se normalizan al formato internacional (`0612345678`, `+33 6 12 34 56 78` y
`33612345678` son equivalentes).

Cuando llega un mensaje de un número mapeado :

- el perfil resuelto se expone en el comando info **« Remitente — perfil »**
  (`last_sender_profile`) — utilizable en escenarios para personalizar las respuestas ;
- se transmite al motor de interacciones de Jeedom a través de la opción `profile`, lo que lo hace
  **compatible con el plugin Perfiles** (reglas de acceso, restricciones, personalización por
  persona).

Si ningún mapeo coincide, el perfil recae sobre el nombre de WhatsApp del remitente,
luego sobre su número en bruto. El comando info permanece vacío cuando el remitente no está mapeado.

> **Ejemplo** : con `33612345678=Papá`, un mensaje « apaga la habitación » enviado desde este
> número es tratado por las interacciones de Jeedom como proveniente del perfil *Papá* — puede
> así autorizar ciertos comandos solo a este perfil mediante el plugin Perfiles.

---

### Respuestas de voz — síntesis de voz / TTS (v0.4)

El plugin puede **hablar** : un texto se sintetiza en **nota de voz** (Opus `.ogg`,
mostrada como mensaje de voz en WhatsApp) gracias a **Piper**, un motor de síntesis
**100 % local** (sin servicios de terceros, sin datos enviados al exterior).

**Dos usos :**

1. **Comando acción « Enviar una nota de voz »** (`send_voice`) — para usar en un
   escenario : el campo *Mensaje* contiene el texto a decir, el campo *Título* un destinatario
   opcional (vacío = grupo canal). Ejemplo : `[Mi WhatsApp][Enviar una nota de voz]` →
   Mensaje : `La temperatura del salón es de 21 grados.`
2. **Modo « vocal-first »** — marque **« Respuestas de voz (TTS) → Activar el modo vocal »**
   en la configuración del equipo. Todas las respuestas automáticas (interacciones
   Jeedom y atajos `/`) se envían entonces como nota de voz en lugar de texto. En caso
   de fallo de la síntesis, el plugin **recae automáticamente en texto**.

**Voz** : la voz francesa `fr_FR-siwis-medium` se instala por defecto. Para usar
otra, coloque un modelo Piper (`.onnx` + `.onnx.json`) en `resources/piper/voices/` e
indique su nombre de archivo en el campo **« Voz de síntesis »** (o una ruta absoluta).

> **Requisito** : `ffmpeg` debe estar instalado en el servidor (presente por defecto en la
> mayoría de instalaciones Jeedom). El binario Piper y la voz francesa se descargan
> automáticamente durante la instalación de las dependencias del plugin. Si la instalación de
> Piper falla, el plugin continúa funcionando — solo las respuestas de voz están
> desactivadas (replegado en texto).

---

### OCR en imágenes recibidas (v0.4)

El plugin puede **leer el texto de las imágenes** recibidas gracias a **Tesseract**, un motor OCR
**100 % local** (sin servicios de terceros).

**Activación** : marque **« OCR imágenes recibidas → Activar »** en la configuración del
equipo. A partir de ese momento, cada imagen recibida en el grupo canal se analiza y el texto
reconocido se coloca en el comando info **« OCR — texto de imagen »** (`last_ocr_text`).

**Idioma** : `fra` (francés) por defecto. El campo acepta varios idiomas combinados con
`+`, por ejemplo `fra+eng` para texto que mezcla francés e inglés. Para español use `spa`.

**Casos de uso** : lectura de contador (agua, gas, electricidad), lectura de un ticket de caja,
de un cartel, de un número de serie… Puede reaccionar al cambio de
`last_ocr_text` en un escenario (extracción de cifras, archivado, alerta…).

> **Requisito** : los paquetes `tesseract-ocr` y `tesseract-ocr-fra` se instalan
> automáticamente (apt) durante la instalación de las dependencias del plugin. Si la instalación
> falla, el OCR simplemente se desactiva — la recepción de medios continúa normalmente.

---

### Transcripción de voz — STT (v0.4)

El plugin puede **transcribir notas de voz** recibidas gracias a **Vosk**, un motor de
reconocimiento de voz **100 % local y sin conexión** (sin servicios de terceros).

**Activación** : marque **« Transcripción de voz (STT) → Activar »** en la configuración
del equipo. A partir de ese momento, cada nota de voz recibida en el grupo canal se transcribe :

- el texto se coloca en el comando info **« STT — nota de voz »** (`last_voice_text`) ;
- se **reinyecta como mensaje de texto**, lo que activa los **atajos** (`/`) y
  las **interacciones de Jeedom** exactamente como si hubiera escrito el mensaje.

Por lo tanto puede **controlar Jeedom por voz** : envíe una nota de voz « *enciende la luz
del salón* » y la interacción de Jeedom correspondiente se ejecuta.

> **Asistente de voz completo** : active tanto **« Transcripción de voz (STT) »** como
> **« Respuestas de voz (TTS) »**. El ciclo se convierte en : nota de voz entrante → transcripción →
> comando Jeedom → **respuesta sintetizada como nota de voz**. Todo permanece local en su servidor.

> **Requisito** : el módulo Python `vosk` y el modelo francés ligero se instalan
> automáticamente durante la instalación de las dependencias del plugin (`ffmpeg` requerido). Si
> la instalación falla, la transcripción simplemente se desactiva — la recepción de notas
> de voz continúa normalmente.

---

### Acuses de recibo (v0.5)

Dos mecanismos complementarios :

- **Marcar como leído** : el comando acción **« Marcar como leído »** (`mark_read`) pone las
  marcas de verificación azules en el último mensaje recibido en el grupo canal. Útil para indicar a sus
  contactos que Jeedom (o usted) ha tomado conocimiento del mensaje.
- **« Leído el »** : el comando info **`last_read_at`** se actualiza automáticamente cuando un
  destinatario **lee o escucha** un mensaje *enviado* por Jeedom. Puede así saber, en
  un escenario, si su alerta ha sido consultada.

---

### Archivar / Fijar / Silenciar (v0.5)

Tres comandos acción controlan el estado de la conversación del grupo canal :

- **Archivar la conversación** (`archive_chat`) — *Título* vacío = archivar, `0` = desarchivar.
- **Fijar la conversación** (`pin_chat`) — *Título* vacío = fijar, `0` = desfijar.
- **Silenciar** (`mute_chat`) — *Título* = duración en horas (vacío = 8 h), `0` = reactivar.

Útil por ejemplo para silenciar automáticamente el grupo por la noche mediante un escenario,
luego reactivarlo por la mañana.

---

### Publicar un estado de WhatsApp (v0.5)

El comando acción **« Publicar un estado »** (`post_status`) publica un **estado efímero de 24 h**
(como una historia) :

- *Mensaje* : el texto del estado (o el pie de foto si se proporciona una imagen) ;
- *Título* : ruta absoluta de una **imagen** opcional (estado imagen).

La audiencia está formada por los **participantes del grupo canal** (son ellos quienes verán el
estado en su hilo). Ejemplo : publicar cada mañana un estado « Casa asegurada ✅ » mediante un
escenario.

---

### Gestión del grupo (v0.5)

La sección **« Gestión del grupo »** (configuración del equipo) agrupa las operaciones
de administración del grupo canal. **La cuenta de WhatsApp vinculada debe ser administrador del grupo.**

- **Participantes** : introduzca un número y use los botones para **añadir**, **retirar**,
  **promover a administrador** o **degradar** a un miembro.
- **Tema** : cambie el nombre/tema del grupo.
- **Enlace de invitación** : genera el enlace `https://chat.whatsapp.com/…` (mostrado y clicable),
  o **revoque** el enlace antiguo para crear uno nuevo.
- **Salir** : hace que la cuenta vinculada salga del grupo (acción irreversible, se pide confirmación).
- **Icono** : el botón « Icono » (junto a Buscar/Crear) aplica el icono del plugin como
  foto del grupo.

---

### Widget del dashboard (v0.6)

En el dashboard de Jeedom, el equipo se muestra como una **tarjeta al estilo WhatsApp** :

- **Cabecera** : avatar (icono del plugin), nombre del equipo y **estado de conexión** en
  directo (punto verde = conectado, naranja = conexión/QR pendiente, rojo = fuera de línea) ;
- **Chat** : el último mensaje recibido en forma de burbuja (remitente + hora) ;
- **Contadores** : mensajes recibidos hoy y enviados en la hora actual ;
- **Envío rápido** : un campo de texto + botón de envío para escribir directamente en el grupo
  canal desde el dashboard ;
- **Botón silenciar** : silencia el grupo (8 h) con un clic.

No se necesita ninguna configuración : el widget está activo en cuanto el equipo es visible en el
dashboard.

---

### Copia de seguridad / restauración de sesión (v0.5)

La conexión de WhatsApp se basa en credenciales almacenadas localmente (`auth/{id}/`). Para no
tener que **volver a escanear el QR code** tras una reinstalación del servidor o una migración, puede
exportar una **copia de seguridad cifrada** de la sesión :

- **Guardar** : introduzca una **frase de contraseña** (mínimo 6 caracteres) y haga clic en
  *Guardar* → se descarga un archivo `.jwab` cifrado (AES-256). Guarde el archivo **y**
  la frase de contraseña en un lugar seguro (la frase es indispensable para restaurar).
- **Restaurar** : seleccione el archivo `.jwab`, introduzca la **misma frase de contraseña**, luego
  haga clic en *Restaurar*. La sesión actual se sobrescribe (la antigua se conserva en `.bak`),
  luego el demonio se reinicia automáticamente.

> El cifrado es completamente local (PHP nativo, sin servicios de terceros). Sin la frase de
> contraseña correcta, el archivo de copia de seguridad es inutilizable.

---

## Escenarios

### Alerta de intruso — mensaje en el grupo

**Disparador :** Detector de movimiento activo entre las 23h y las 6h

**Acciones :**
- `[Mi WhatsApp][Enviar un mensaje]` → Mensaje : `⚠️ ¡Movimiento detectado en el salón!`

El mensaje llega al grupo canal con el prefijo de Jeedom.

### Comando por palabra clave con respuesta en el grupo

**Disparador :** `[Mi WhatsApp][Último mensaje]` cambia

**Condición :** `[Mi WhatsApp][Último mensaje]` contiene `luz`

**Acciones :**
- Encender la luz del salón
- `[Mi WhatsApp][Responder]` → Mensaje : `💡 ¡Luz encendida!`

### Informe diario en el grupo

**Disparador :** Todos los días a las 8:00

**Acciones :**
- `[Mi WhatsApp][Enviar un mensaje]` → Mensaje : `☀️ ¡Buenos días! Temperatura salón : [Salón][Temperatura]°C`

### Compartir una ubicación 📍

**Disparador :** Botón virtual "Compartir mi casa"

**Acciones :**
- `[Mi WhatsApp][Enviar una ubicación]` → Título : `48.8566|2.3522|Casa`

### Enviar la tarjeta de contacto del médico

**Disparador :** Palabra clave "médico" recibida en el grupo

**Acciones :**
- `[Mi WhatsApp][Enviar un contacto]` → Título : `33112345678`, Mensaje : `Dr. García — consulta`

### Confirmar la recepción de un comando con una reacción ❤️

**Disparador :** Un miembro del grupo envía la palabra "gracias"

**Acciones :**
- `[Mi WhatsApp][Reaccionar al último mensaje]` → Mensaje : `❤️`

### Encender un ambiente según la reacción recibida

**Disparador :** `[Mi WhatsApp][Última reacción]` cambia

**Condiciones :**
- Si `[Mi WhatsApp][Última reacción]` = `❤️` → encender ambiente romántico
- Si `[Mi WhatsApp][Última reacción]` = `🎉` → encender ambiente fiesta
- Si `[Mi WhatsApp][Última reacción]` = `🌙` → modo noche

### Procesar una foto de contador enviada por WhatsApp

**Disparador :** `[Mi WhatsApp][Último medio — tipo]` cambia

**Condiciones :** si `[...][Último medio — tipo]` = `image`

**Acciones :**
- Copiar `[...][Último medio — ruta]` a `/var/www/html/data/contadores/`
- (avanzado) Llamar a un script OCR en la imagen, extraer el valor, actualizar un virtual

> **📥 Recepción de medios (v0.2)**
> Las imágenes, vídeos, notas de voz, documentos y stickers recibidos en el grupo canal
> se descargan automáticamente en `data/jeewhatsapp/incoming/{eqId}/{YYYY-MM-DD}/{uuid}.ext`.
> Los archivos se conservan **30 días** y luego se eliminan por cron (`cronCleanupIncoming` a las 03:15).
> La ruta se expone a través de los 4 cmds info `last_attachment_*` — sus escenarios pueden
> copiarlos a otro lugar, analizarlos (OCR, visión), o transferirlos.

---

## Resolución de problemas

### No recibo notificaciones de WhatsApp cuando Jeedom envía un mensaje

Es una limitación de WhatsApp : una cuenta no recibe notificaciones por sus propios mensajes,
ni siquiera con una mención `@usted`. La única solución es usar una segunda cuenta de WhatsApp dedicada a Jeedom
(consulte la sección "Limitación — notificaciones push" en la Presentación).

### El demonio no se inicia

- Verifique que Node.js 18+ está instalado : `node --version` en un terminal
- Relance la instalación de las dependencias
- Consulte el log `jeewhatsapp` en **Análisis → Logs**
- Verifique que el puerto `55148` no está ya en uso

### El QR code no se muestra

- Verifique que el demonio está iniciado (estado OK en la gestión del plugin)
- Reinicie el demonio y luego actualice la página
- Consulte el log `jeewhatsapp` para detectar un error de Baileys

### El grupo canal no se encuentra al inicio

- Verifique que el nombre en **Grupo canal** corresponde exactamente al nombre del grupo de WhatsApp (sensible a mayúsculas/minúsculas)
- El grupo debe existir en WhatsApp y su cuenta debe ser miembro del mismo
- Haga clic en **Buscar** en la página de configuración para forzar la búsqueda manualmente
- Consulte el log `jeewhatsapp` : el demonio muestra `Grupo "xxx" no encontrado` con el nombre buscado

### Los mensajes no se reciben

- Verifique que el estado en la pestaña **Conexión WhatsApp** muestra **Conectado**
- Verifique que el grupo canal está bien vinculado (campo **Grupo vinculado** rellenado)
- Solo los mensajes de texto del grupo canal se procesan — los medios se ignoran
- Consulte el log `jeewhatsapp` para verificar que el demonio recibe bien los mensajes

### No se recibe ningún mensaje y el log está saturado de « Bad MAC »

Si el log `jeewhatsapp` muestra en bucle `Failed to decrypt message with any known session`
o `Session error: Bad MAC`, es que la **sesión cifrada (Signal) está corrompida** :
WhatsApp envía bien los mensajes pero el demonio ya no puede descifrarlos, por lo que se
abandonan silenciosamente. Esto ocurre en particular tras reinicios rápidos del demonio
o un uso concurrente de la misma sesión.

**Solución : volver a vincular el dispositivo.**

1. En su teléfono : **WhatsApp → Dispositivos conectados**, elimine el dispositivo « JeeWhatsApp ».
2. En el servidor, elimine (o renombre) la carpeta de sesión del equipo :
   `plugins/jeewhatsapp/resources/jeewhatsappd/auth/{ID_equipo}/`
3. Reinicie el demonio desde la gestión del plugin.
4. Un nuevo QR code aparece en la pestaña **Conexión WhatsApp** — vuelva a escanearlo.

Las claves de cifrado se regeneran y la recepción vuelve a funcionar.

### El envío falla

- Verifique que el estado de WhatsApp es **Conectado**
- Verifique que el grupo vinculado está indicado (campo **Grupo vinculado** no vacío)
- Pruebe desde la pestaña **Test** de la página de equipo (deje Destinatario vacío para enviar al grupo)
- Consulte el log `jeewhatsapp`

### WhatsApp ha desconectado la sesión (logout)

Cuando WhatsApp revoca el acceso al dispositivo vinculado :
1. El demonio detecta la desconexión y elimina las credenciales
2. En la pestaña **Conexión WhatsApp**, aparece automáticamente un nuevo QR code
3. Vuelva a escanear el QR code para reconectar
4. Al reconectar, el demonio busca automáticamente el grupo canal

---

## Arquitectura técnica

```
Grupo WhatsApp "jeewhatsapp"
       │
       │  (WebSocket Baileys — conexión directa)
       │
  jeewhatsappd.js
       │
       ├─ messages.upsert
       │    ├─ Filtro : remoteJid === groupJid ? → si no, ignorado
       │    ├─ Filtro : fromMe ? → ignorado (mensaje de Jeedom)
       │    └─ POST callback.php?apikey=
       │         └─ jeewhatsapp::callback()
       │              └─ cmd.event() → [last_message, last_sender, …]
       │
       └─ /action (HTTP local 127.0.0.1:55148)
            ├─ send        → sock.sendMessage(jid, { text: prefijo + mensaje })
            ├─ findGroup   → sock.groupFetchAllParticipating()
            ├─ createGroup → sock.groupCreate(name, [])
            ├─ getQR       → lee auth/{id}/qr.txt
            └─ getStatus   → lee auth/{id}/status.txt
```

### Componentes

| Componente | Tecnología | Función |
|---|---|---|
| `jeewhatsappd.js` | Node.js (ESM) | Demonio — conexión Baileys + servidor HTTP local |
| `jeewhatsapp.class.php` | PHP | Lógica Jeedom, ciclo de vida del demonio, comandos |
| `callback.php` | PHP | Endpoint de recepción de mensajes del demonio |
| `jeewhatsapp.ajax.php` | PHP | Acciones AJAX (test, QR code, findGroup, createGroup) |

### Auth y sesiones

Las credenciales de Baileys se almacenan en `resources/jeewhatsappd/auth/{eqLogicId}/`.
Una subcarpeta por equipo permite gestionar varias cuentas de WhatsApp simultáneamente.

| Archivo | Contenido |
|---|---|
| `*.json` | Credenciales Baileys (multi-file auth state) |
| `qr.txt` | QR code base64 temporal (eliminado al conectar) |
| `status.txt` | Estado actual : `connecting`, `connected`, `qr_pending`, `reconnecting`, `logged_out` |
| `group_jid.txt` | JID del grupo canal (en caché para acelerar el reinicio) |

---

## Acerca de

- **Plugin :** JeeWhatsApp v0.1
- **Licencia :** AGPL v3
- **Backend :** [Baileys](https://github.com/WhiskeySockets/Baileys) — open-source, licencia MIT
- **WhatsApp** es una marca registrada de Meta Platforms, Inc.
- Este plugin no está afiliado a Meta ni a WhatsApp.
