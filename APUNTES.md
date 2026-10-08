INGRESOS
- INTERESES
	- vehicular
    - diario
    - hipotecario
    - prendario
- VENTA DE PRODUCTOS


PLANILLA
- SUELDOS
- ASISTENCIAS 
    - APLICATIVO MARCA SU ASISTENCIA
    - CAPTURAR GPS
    - CAPTURAR HORA
    - USAR FACIAL
    - FOTO DEL USUARIO
- DESCUENTOS
- RIESGO DE CAJA - POR USUARIO
- SEGUROS (AFP)
- FALTAS
- ADELANTOS
- PRESTAMO PARA EL USUARIO (SE DESCUENTA DE SUELDO)
- NUMERO DE CUENTA - YAPE
- MODULO DE REGISTRO DE DIAS LABORABLES (ASISTENCIA)
- MODULO DE VACACIONES

USUARIO DEBE TENER OPCION DE ESTAR EN PLANILLA SI O NO

 el usuario debe tener un horario de trabajo
 COBRADOR - PEINADOR - SUPERVISOR
 HORARIO
 8 - 12 - 2 - 6
 8 - 12 - 1 - 5
 9 - 1  - 2 - 6

FECHA DE INGRESO
FECHA DE INGRESO A PLANILLA

 -----------------
 # CLIENTES
 - CLIENTES EN LISTA NEGRA DESPUES DE 3 MESES PASAR A BOVEDA DE CLIENTES PERDIDA

- REPORTE CLIENTES NUEVOS REGISTRADOS POR USUARIO


## REPORTE
- UTILIDAD POR COBRADOR - SUPERVISOR - AGENCIA
    *CAPITAL DE PRESTAMOS(SIN INTERES) EN RELACIÓN CON LAS COBRANZAS*

MODULO FACTURACION
- SELECCIONAR EL CLIENTE PARA EMITIR LA FACTURACION

- CLIENTE AGREGAR DNI/RUC/CE

**ALERTA PARA EMITIR FACTURACION DIARIO**
CONTADOR TOTAL DE CUANTO SE HA EMITIDO HASTA LA FECHA
REPORTE DE LAS BOLETAS EMITIDAS

-------------------
credito hipotecario
- generar un documento de SALDO deudor
- CANCELACION de credito, DEBE ESTAR AL DIA EN SUS CUOTAS Y RECIEN PUEDE CANCELAR CREDITO

TASA MAXIMA BCR
https://tasamaxima.bcrp.gob.pe/tasa-max-interes/web/public/index.php?accion=consultaSF

-----
Agregar nuevos campos en el usuario:
- 

Implementar un nuevo reporte de PLANILLA

Datos del trabajador                                            | Información declarada
Documento       | apellidos | nombres   | Situación             | ingresos              | descuentos    | Tributos y                | Neto a pagar  | Tributos y
Tipo | número   |           |           |                       | devengado | pagado    |               | aportes de trabajador     |               | aportes del empleador
---
01   | 74901226 | QUISPE QU | NORMA     | Activo o Subcidiado   | 1130.00   | 1130.00   | 0.00          | 128.48                    | 1001.52       | 101.70


-----

Quiero implementar un nuevo reporte de INGRESOS Y EGRESOS

Debe tener un filtro por agencia

Ingresos
Cuenta | Fecha | Descripción | Monto
Total:

Cobranzas
Cuenta | Fecha | Descripción | Monto
Total:

Ventas
Cuenta | Fecha | Descripción | Monto
Total:

Egresos
Cuenta | Fecha | Descripción | Monto
Total:

Desembolsos
Cuenta | Fecha | Descripción | Monto
Total:


Utilidad/Perdida Neta: (Ingresos+Cobranzas+Ventas) - (Egresos+Desembolsos)
Margen de Utilidad Bruta: (Ingresos+Cobranzas+Ventas)/(Egresos+Desembolsos)