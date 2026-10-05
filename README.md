# Anuncio entre productos · Audio Pro

Plugin de WordPress para WooCommerce que inserta anuncios dentro de la grilla de productos de la tienda. Cada anuncio ocupa el lugar de un producto y puede ser una **imagen**, un **video** subido a la biblioteca de medios o un **video de YouTube**, incluidos los Shorts.

Está pensado para el tema Flatsome, pero funciona con cualquier tema que use la grilla estándar de WooCommerce.

## Funciones

- **Tres tipos de anuncio.** Imagen con enlace, video que se reproduce solo, sin sonido y en bucle, o video de YouTube que se carga al tocarlo.
- **Vista previa de la grilla.** Muestra dónde cae cada anuncio. Al hacer clic en una casilla se elige qué poner ahí.
- **Fechas de inicio y fin** para programar campañas.
- **Filas sin anuncios** para dejar libres las filas que quieras.
- **Orden aleatorio** de los anuncios generales en cada visita.
- **Anuncios por categoría o marca.** Cada una puede usar los anuncios generales, tener los suyos o no mostrar ninguno.
- **Avisos en el panel** por imágenes faltantes, fechas al revés, videos pesados, archivos .mov y enlaces de YouTube no válidos.

## Requisitos

- WordPress 5.8 o superior
- PHP 7.4 o superior
- WooCommerce activo

## Instalación

1. Descarga el archivo `ap-anuncio-flatsome-mejorado-vX.Y.Z.zip` de la sección **Releases** del repositorio.
2. En WordPress ve a **Plugins › Añadir nuevo › Subir plugin** y sube ese zip.
3. Actívalo.

> No uses el botón verde **Code › Download ZIP**. Ese zip trae la carpeta con otro nombre y WordPress lo instalaría como un plugin distinto en lugar de actualizar el que ya tienes.

Para actualizar desde una versión anterior, sube el zip nuevo y elige **Reemplazar la versión actual**. Los anuncios guardados se conservan.

## Configuración

- **Anuncios generales:** WooCommerce › Anuncios entre productos.
- **Anuncios de una categoría o marca:** Productos › Categorías, o Productos › Marcas, y edita la que quieras. La sección se llama «Anuncios entre productos».

Consejos:

- Indica cuántos productos por fila muestra tu tienda en computadora. El panel avisa si no coincide con la configuración de Flatsome.
- Usa imágenes con la misma proporción que las fotos de tus productos.
- Los videos conviene que sean MP4, duren menos de 15 segundos y pesen menos de 5 MB.
- El reproductor de YouTube siempre muestra el título y el logo. Para un video limpio, sube el archivo original como anuncio de tipo «Video».

## Estructura

```
ap-anuncio-flatsome-mejorado.php   Lógica del plugin, panel y salida en la tienda
assets/admin.js                    Vista previa y tarjetas del panel
assets/admin.css                   Estilos del panel
assets/front.js                    Reproducción de videos y carga de YouTube en la tienda
```

## Historial de versiones

### 1.14.0
- Anuncios de video subido y de YouTube, con forma elegible y portada opcional.
- Botón de oferta opcional debajo de los videos de YouTube.
- Un archivo borrado de la biblioteca ya no corre los demás anuncios de casilla.

### 1.13.0
- Panel rediseñado con vista previa de la grilla y tarjetas con estado.
- Selector de modo en categorías y marcas, y resumen de las que tienen configuración propia.
- Detección de columnas de Flatsome y mensaje de ajustes guardados.

### 1.12.1
- Se muestran los anuncios que quedan justo después del último producto.
- Dos anuncios en la misma casilla ya no se pierden.
- Compatibilidad declarada con HPOS de WooCommerce.

### 1.12.0
- Versión original.

## Licencia

GPLv2 o posterior.
