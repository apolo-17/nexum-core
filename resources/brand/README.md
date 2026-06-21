# nexumcore — manual rápido de marca

## Sistema de color
| Rol | Hex | Uso |
|---|---|---|
| Azul núcleo (primario) | `#185FA5` | Logo, botones primarios, enlaces |
| Azul profundo | `#0C447C` | Texto sobre claro, hover, logo oscuro |
| Azul tinta | `#042C53` | Fondo de login oscuro, header |
| Azul claro (acento) | `#378ADD` | Detalle del sello, estado activo, gráficos |
| Azul bruma | `#B5D4F4` | Acento sobre fondo oscuro |
| Neutro texto | `#0F1729` | Texto principal |
| Neutro secundario | `#5F6B7A` | Texto secundario |
| Fondo app | `#F5F7FA` | Lienzo de la plataforma |
| Éxito (funcional) | `#1D9E75` | "Empresa constituida" |
| Pendiente (funcional) | `#BA7517` | "Acción requerida" |

El logo se mantiene monocromático azul. Los acentos verde/ámbar son solo para estados en la UI.

## Qué archivo uso para qué

### Favicon (pestaña del navegador)
- `favicon.ico` — el clásico, ponlo en la raíz del sitio.
- `favicon.svg` — favicon vectorial moderno (Chrome/Firefox).
- `favicon-16/32/48.png` — tamaños sueltos si los necesitas.
- `apple-touch-icon-180.png` — ícono al guardar en pantalla de inicio iOS.
- `icon-512.png` / `icon-512-white.png` — ícono PWA / manifest.

```html
<link rel="icon" href="/favicon.ico" sizes="any">
<link rel="icon" href="/favicon.svg" type="image/svg+xml">
<link rel="apple-touch-icon" href="/apple-touch-icon-180.png">
```

### Logo de login y header de la plataforma
- `lockup-color@1x.png` (320px) / `@2x.png` (640px, pantallas retina) — sobre fondo claro.
- `lockup-white@1x.png` / `@2x.png` — sobre fondo oscuro (`#042C53`).
- `lockup-color.svg` / `lockup-white.svg` — vectorial, escala infinita (recomendado para web).

### Solo símbolo (sello) / solo texto (wordmark)
- `seal-color.svg` / `seal-white.svg` / `seal-mono.svg` — el medallón solo.
- `wordmark-color.svg` / `wordmark-white.svg` / `wordmark-mono.svg` — solo el nombre.

`mono` = versión de un solo tono para documentos legales, sellos impresos, fax/escala de grises.

## Nota sobre la tipografía
El wordmark está en curvas (no depende de fuentes instaladas), pero usa DejaVu Sans
como base — es funcional, no definitiva. Para identidad final recomiendo licenciar una
geométrica (Montserrat, Poppins o Inter) y regenerar el wordmark. Es un cambio de 2 minutos.
