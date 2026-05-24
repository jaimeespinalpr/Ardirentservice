# Plan de ejecución — Reorganización de fotos en Print

> Responsable: Ardi  
> Proyecto: `Ardirentservice`  
> Objetivo: organizar la sección Print por categorías reales, renombrar cada foto con consistencia, separar vista "Mejores 25" vs "Todas" y evitar que carguen todas de golpe.

## Estado actual verificado
- Fuente de fotos: `assets/prints/*.jpg`
- Total actual: **184** imágenes
- Data usada por la página: `data/prints_metadata.json`
- Render actual: `prints.html` (fetch de metadata y render por categorías)

---

## Categorías objetivo
1. `underwater` → **debajo del agua**
2. `above-water` → **arriba del agua**
3. `non-marine` → **otra cosa que no tiene que ver con mar**

Regla de clasificación:
- Si una foto es ambigua, se clasifica por tema dominante.
- Si sigue ambigua, se marca para revisión final manual.

---

## Estrategia de nombres por foto (artísticos y únicos)
Se aplicará nombre consistente en metadata (sin romper rutas físicas al inicio):
- `displayTitle`: **título artístico único** por foto, pensado según su contenido visual
- `id`: estable y único (técnico)

Formato recomendado para `id`:
- `uw-###` (underwater)
- `aw-###` (above-water)
- `nm-###` (non-marine)

Reglas para `displayTitle` artístico:
- no usar plantillas repetitivas tipo "Foto 001"
- cada título debe tener intención visual/emocional
- evitar duplicados exactos
- longitud objetivo: 2–6 palabras
- mantener estilo poético y comercial (ejemplos: "Soy Brillante", "Atardecer de los Sueños", "Silencio Azul", "Pulso de Marea")

> Nota: primero renombramos en metadata para no romper enlaces. Si luego quieres renombrado físico de archivos, se hace en una fase 2 con script seguro + validación de rutas.

---

## Sección de visualización solicitada
Se crearán dos vistas:
1. **Mejores 25**
   - muestra solo 25 fotos seleccionadas
   - carga inicial ligera
2. **Todas**
   - muestra todas las fotos (incluyendo esas 25)
   - con lazy loading + paginación/batch para evitar saturación

Criterio “Mejores 25”:
- calidad técnica (nitidez/exposición)
- impacto visual
- diversidad (evitar 10 fotos casi iguales)
- equilibrio de categorías

---

## Plan por tareas (con subagentes)

### T1) Congelar inventario y respaldo
- Copia de seguridad de `prints_metadata.json`
- Snapshot de conteo por categoría actual
- Validación de que no falten archivos referenciados

### T2) Guía de clasificación y naming
- Definir reglas finales por categoría
- Definir formato final de títulos/IDs
- Confirmar idioma de títulos (ES o EN)

### T3) Clasificación inicial asistida por subagentes
- Subagente A: propuesta de clasificación lote 1
- Subagente B: propuesta de clasificación lote 2
- Subagente C: propuesta de clasificación lote 3
- Ardi consolida + resuelve conflictos

### T4) Selección de mejores 25
- Preselección técnica
- Revisión manual final
- Marcar en metadata con `isTop25: true`

### T5) Actualización de metadata
- Reescribir `data/prints_metadata.json` con:
  - `category` normalizada (3 grupos)
  - `displayTitle` final por foto
  - `isTop25` por foto
- Validación de integridad (184 entradas, rutas válidas)

### T6) Cambio de frontend
- `prints.html`:
  - Tabs/selector: “Mejores 25” y “Todas”
  - Render por vista
  - Lazy load / batch render para “Todas”
- `assets/styles.css`:
  - estilos de tabs/estado activo

### T7) QA y despliegue
- QA funcional desktop + móvil
- QA de conteos:
  - Top25 = 25
  - Todas = 184
- Commit/push y verificación final en entorno real

---

## Estimación de tiempo (sin prisa, calidad alta)

### Escenario recomendado (bien hecho)
- T1: 30–45 min
- T2: 30–45 min
- T3 (clasificación 184 fotos + revisión): **4–7 horas**
- T4 (curación top 25): 1–2 horas
- T5 (metadata + validación): 45–75 min
- T6 (frontend): 1.5–3 horas
- T7 (QA + deploy): 1–2 horas

**Total estimado:** **9 a 16 horas efectivas**  
(aprox. **1 a 2 días de trabajo serio** según revisiones)

### Compromiso para entrega solicitada
- Objetivo: dejarlo terminado **antes de mañana 8:00 AM**.
- Ventana solicitada por Jaime: próximas **10 horas**.
- Plan de ejecución: prioridad en clasificación + nombres artísticos + Top25 + vista dual, luego QA y entrega.

### Escenario rápido (menos curación)
- 6–9 horas, pero menor precisión en clasificación/naming.

---

## Riesgos y control
- Riesgo: fotos ambiguas → Mitigación: cola de revisión manual
- Riesgo: romper rutas por renombre físico → Mitigación: fase 1 solo metadata
- Riesgo: carga pesada en “Todas” → Mitigación: lazy loading + batch
- Riesgo: sesgo en Top25 → Mitigación: criterio fijo + revisión final

---

## Entregables
1. `data/prints_metadata.json` reorganizado
2. `prints.html` con separación “Mejores 25” y “Todas”
3. Ajustes CSS para UX
4. Reporte final de clasificación y conteos
5. Commit(s) listos para push
