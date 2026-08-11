<?php

namespace App\Modules\SystemSuperadmin\Services;

class BusinessSpecializedBlueprintFactory
{
    /**
     * @return array<string, array<string, mixed>>
     */
    public function all(): array
    {
        return [
            'Odontologia' => $this->dental(),
            'Consultorio medico' => $this->healthClinic(),
            'Medicina estetica y spa' => $this->aestheticMedicine(),
            'Veterinaria' => $this->veterinary(),
            'Gimnasio' => $this->gym(),
            'Mecanica autos y motos' => $this->vehicleWorkshop('auto_mechanic', 'vehiculo', 'Vehiculo', 'Vehiculos'),
            'Reparacion celulares y laptops' => $this->equipmentRepair(),
            'Lavadero de autos' => $this->carWash(),
            'Mudanzas' => $this->movingService(),
            'Arquitectura y construccion' => $this->construction(),
            'Prestamos y casa de empeno' => $this->loans(),
            'Hotel y hostal' => $this->reservationBusiness('habitacion', 'Habitacion', 'Habitaciones'),
            'Canchas deportivas' => $this->reservationBusiness('cancha', 'Cancha', 'Canchas'),
            'Alquiler equipos y herramientas' => $this->reservationBusiness('equipo_alquilable', 'Equipo o herramienta alquilable', 'Equipos y herramientas alquilables'),
            'Eventos y salones' => $this->reservationBusiness('salon_evento', 'Salon/evento', 'Salones/eventos'),
            'Transporte y logistica' => $this->transportLogistics(),
            'Educacion y cursos' => $this->education(),
        ];
    }

    /**
     * @return array<string, mixed>|null
     */
    public function forPreset(string $presetName): ?array
    {
        return $this->all()[$presetName] ?? null;
    }

    /**
     * @return array<int, string>
     */
    public function presetNames(): array
    {
        return array_keys($this->all());
    }

    /**
     * @return array<string, mixed>
     */
    private function dental(): array
    {
        return $this->mergeBlueprints(
            $this->healthBase('dental_clinic', 'Paciente odontologico'),
            [
                'entities' => [
                    $this->entity('odontograma', 'Odontograma', 'Odontogramas', 'services', true),
                    $this->entity('tratamiento_dental', 'Tratamiento dental', 'Tratamientos dentales', 'services'),
                ],
                'fields' => [
                    ...$this->fields('odontograma', [
                        ['pieza_dental', 'Pieza dental', 'select', true, ['11', '12', '13', '14', '15', '16', '17', '18', '21', '22', '23', '24', '25', '26', '27', '28', '51', '52', '53', '54', '55', '61', '62', '63', '64', '65']],
                        ['tipo_denticion', 'Tipo de denticion', 'select', true, ['adulto', 'nino']],
                        ['estado_pieza', 'Estado de la pieza', 'select', true, ['sana', 'caries', 'restaurada', 'extraida', 'tratamiento']],
                    ], 'Odontograma'),
                    ...$this->fields('tratamiento_dental', [
                        ['procedimiento', 'Procedimiento', 'text', true],
                        ['costo_estimado', 'Costo estimado', 'currency', false],
                        ['materiales_previstos', 'Materiales previstos', 'text', false],
                    ], 'Tratamiento'),
                ],
                'relationships' => [
                    $this->relationship('paciente_odontogramas', 'Paciente tiene odontogramas', 'paciente', 'odontograma'),
                    $this->relationship('odontograma_tratamientos', 'Odontograma tiene tratamientos', 'odontograma', 'tratamiento_dental'),
                ],
                'forms' => [
                    $this->form('odontograma', 'odontograma_atencion', 'Registro de odontograma', ['pieza_dental', 'tipo_denticion', 'estado_pieza'], 'Finalizar registro'),
                ],
                'workflows' => [
                    $this->workflow('tratamiento_dental', 'flujo_tratamiento_dental', 'Tratamiento dental', 'presupuestado', ['finalizado', 'cancelado'], [
                        ['presupuestado', 'aprobado', 'Aprobar presupuesto'],
                        ['aprobado', 'en_tratamiento', 'Iniciar tratamiento'],
                        ['en_tratamiento', 'finalizado', 'Finalizar tratamiento'],
                        ['presupuestado', 'cancelado', 'Cancelar'],
                    ]),
                ],
            ],
        );
    }

    /**
     * @return array<string, mixed>
     */
    private function healthClinic(): array
    {
        return $this->mergeBlueprints($this->healthBase('health_clinic', 'Paciente medico'), [
            'forms' => [
                $this->form('paciente', 'consulta_medica', 'Consulta medica', ['motivo_consulta', 'antecedentes', 'diagnostico'], 'Guardar consulta'),
            ],
            'workflows' => [
                $this->workflow('paciente', 'flujo_atencion_medica', 'Atencion medica', 'registrado', ['alta', 'derivado'], [
                    ['registrado', 'en_consulta', 'Iniciar consulta'],
                    ['en_consulta', 'control_pendiente', 'Programar control'],
                    ['control_pendiente', 'alta', 'Dar alta'],
                    ['en_consulta', 'derivado', 'Derivar'],
                ]),
            ],
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    private function aestheticMedicine(): array
    {
        return [
            'entities' => [
                $this->entity('sesion_estetica', 'Sesion estetica', 'Sesiones esteticas', 'services', true),
            ],
            'fields' => [
                ...$this->fields('sesion_estetica', [
                    ['servicio_aplicado', 'Servicio aplicado', 'text', true],
                    ['numero_sesion', 'Numero de sesion', 'number', true],
                    ['material_usado', 'Material usado', 'text', false],
                    ['foto_antes', 'Foto antes', 'image', false],
                    ['foto_despues', 'Foto despues', 'image', false],
                ], 'Sesion'),
            ],
            'forms' => [
                $this->form('sesion_estetica', 'cerrar_sesion_estetica', 'Cerrar sesion estetica', ['servicio_aplicado', 'numero_sesion', 'material_usado', 'foto_despues'], 'Cerrar sesion'),
            ],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function veterinary(): array
    {
        return [
            'entities' => [
                $this->entity('mascota', 'Mascota', 'Mascotas', 'customers', true),
                $this->entity('consulta_veterinaria', 'Consulta veterinaria', 'Consultas veterinarias', 'services', true),
            ],
            'fields' => [
                ...$this->fields('mascota', [
                    ['nombre_mascota', 'Nombre de mascota', 'text', true],
                    ['especie', 'Especie', 'select', true, ['perro', 'gato', 'ave', 'otro']],
                    ['raza', 'Raza', 'text', false],
                    ['fecha_nacimiento', 'Fecha de nacimiento', 'date', false],
                ], 'Mascota'),
                ...$this->fields('consulta_veterinaria', [
                    ['motivo', 'Motivo', 'text', true],
                    ['diagnostico', 'Diagnostico', 'text', false],
                    ['vacuna', 'Vacuna aplicada', 'text', false],
                ], 'Consulta'),
            ],
            'relationships' => [
                $this->relationship('cliente_mascotas', 'Cliente tiene mascotas', 'customer', 'mascota'),
                $this->relationship('mascota_consultas', 'Mascota tiene consultas', 'mascota', 'consulta_veterinaria'),
            ],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function gym(): array
    {
        return [
            'entities' => [
                $this->entity('membresia', 'Membresia', 'Membresias', 'services', true),
            ],
            'fields' => [
                ...$this->fields('membresia', [
                    ['plan', 'Plan', 'select', true, ['diario', 'mensual', 'trimestral', 'anual']],
                    ['inicio', 'Inicio', 'date', true],
                    ['fin', 'Fin', 'date', true],
                    ['estado_membresia', 'Estado', 'select', true, ['activa', 'vencida', 'congelada']],
                ], 'Membresia'),
            ],
            'forms' => [
                $this->form('membresia', 'registrar_membresia', 'Registrar membresia', ['plan', 'inicio', 'fin'], 'Activar membresia'),
            ],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function equipmentRepair(): array
    {
        return $this->mergeBlueprints(
            $this->vehicleWorkshop('electronics_repair', 'equipo', 'Equipo', 'Equipos'),
            [
                'fields' => [
                    ...$this->fields('equipo', [
                        ['marca', 'Marca', 'text', true],
                        ['modelo', 'Modelo', 'text', true],
                        ['imei_serie', 'IMEI o serie', 'text', false],
                        ['clave_acceso', 'Clave o patron entregado', 'text', false],
                    ], 'Equipo'),
                ],
            ],
        );
    }

    /**
     * @param array<string, mixed> $extraCapabilities
     * @return array<string, mixed>
     */
    private function vehicleWorkshop(string $businessType, string $assetEntity, string $assetLabel, string $assetPlural): array
    {
        return [
            'entities' => [
                $this->entity($assetEntity, $assetLabel, $assetPlural, 'customers', true),
                $this->entity('orden_servicio_extendida', 'Orden de servicio extendida', 'Ordenes de servicio extendidas', 'service_orders', true),
            ],
            'fields' => [
                ...$this->fields($assetEntity, [
                    ['placa', 'Placa', 'text', false],
                    ['kilometraje', 'Kilometraje', 'number', false],
                    ['vin', 'VIN o chasis', 'text', false],
                ], $assetLabel),
                ...$this->fields('orden_servicio_extendida', [
                    ['diagnostico', 'Diagnostico', 'text', true],
                    ['materiales_usados', 'Materiales usados', 'text', false],
                    ['garantia_dias', 'Garantia en dias', 'number', false],
                    ['evidencia_entrega', 'Evidencia de entrega', 'image', false],
                ], 'Orden'),
            ],
            'relationships' => [
                $this->relationship('cliente_'.$assetEntity, 'Cliente tiene '.$assetPlural, 'customer', $assetEntity),
                $this->relationship($assetEntity.'_ordenes', $assetLabel.' tiene ordenes', $assetEntity, 'orden_servicio_extendida'),
            ],
            'forms' => [
                $this->form('orden_servicio_extendida', 'cerrar_orden_servicio', 'Cerrar orden de servicio', ['diagnostico', 'materiales_usados', 'garantia_dias', 'evidencia_entrega'], 'Cerrar orden'),
            ],
            'workflows' => [
                $this->workflow('orden_servicio_extendida', 'flujo_servicio_tecnico', 'Servicio tecnico', 'recibido', ['entregado', 'cancelado'], [
                    ['recibido', 'diagnostico', 'Diagnosticar'],
                    ['diagnostico', 'esperando_repuesto', 'Esperar repuesto'],
                    ['diagnostico', 'en_reparacion', 'Iniciar reparacion'],
                    ['esperando_repuesto', 'en_reparacion', 'Continuar reparacion'],
                    ['en_reparacion', 'listo', 'Marcar listo'],
                    ['listo', 'entregado', 'Entregar'],
                    ['recibido', 'cancelado', 'Cancelar'],
                ]),
            ],
            'metadata' => ['business_type' => $businessType],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function carWash(): array
    {
        return [
            'entities' => [
                $this->entity('vehiculo_lavadero', 'Vehiculo', 'Vehiculos', 'customers'),
                $this->entity('servicio_lavado', 'Servicio de lavado', 'Servicios de lavado', 'services'),
            ],
            'fields' => [
                ...$this->fields('vehiculo_lavadero', [
                    ['placa', 'Placa', 'text', false],
                    ['tipo_vehiculo', 'Tipo de vehiculo', 'select', true, ['auto', 'moto', 'camioneta', 'camion']],
                ], 'Vehiculo'),
                ...$this->fields('servicio_lavado', [
                    ['tipo_lavado', 'Tipo de lavado', 'select', true, ['basico', 'completo', 'motor', 'detailing']],
                    ['responsable', 'Responsable', 'text', false],
                ], 'Lavado'),
            ],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function movingService(): array
    {
        return [
            'entities' => [
                $this->entity('servicio_mudanza', 'Servicio de mudanza', 'Servicios de mudanza', 'services', true),
            ],
            'fields' => [
                ...$this->fields('servicio_mudanza', [
                    ['origen', 'Origen', 'text', true],
                    ['destino', 'Destino', 'text', true],
                    ['volumen_m3', 'Volumen m3', 'decimal', false],
                    ['ayudantes', 'Ayudantes', 'number', false],
                    ['distancia_km', 'Distancia km', 'decimal', false],
                ], 'Mudanza'),
            ],
            'formulas' => [
                $this->formula('servicio_mudanza', 'costo_mudanza_base', 'Costo mudanza base', 'currency', [
                    'op' => 'add',
                    'args' => [
                        ['op' => 'multiply', 'args' => [['var' => 'distancia_km'], ['var' => 'tarifa_km']]],
                        ['op' => 'multiply', 'args' => [['var' => 'ayudantes'], ['var' => 'costo_ayudante']]],
                    ],
                ], ['distancia_km', 'tarifa_km', 'ayudantes', 'costo_ayudante']),
            ],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function construction(): array
    {
        return [
            'entities' => [
                $this->entity('proyecto_obra', 'Proyecto de obra', 'Proyectos de obra', 'services', true),
                $this->entity('computo_metrico', 'Computo metrico', 'Computos metricos', 'services', true),
            ],
            'fields' => [
                ...$this->fields('proyecto_obra', [
                    ['ubicacion', 'Ubicacion', 'text', true],
                    ['cliente_responsable', 'Cliente responsable', 'text', true],
                    ['etapa_actual', 'Etapa actual', 'select', false, ['diseno', 'presupuesto', 'ejecucion', 'entrega']],
                ], 'Proyecto'),
                ...$this->fields('computo_metrico', [
                    ['area_m2', 'Area m2', 'decimal', false],
                    ['volumen_m3', 'Volumen m3', 'decimal', false],
                    ['espesor_cm', 'Espesor cm', 'decimal', false],
                    ['dosificacion', 'Dosificacion', 'text', false],
                ], 'Computo'),
            ],
            'relationships' => [
                $this->relationship('proyecto_computos', 'Proyecto tiene computos', 'proyecto_obra', 'computo_metrico'),
            ],
            'formulas' => [
                $this->formula('computo_metrico', 'volumen_losa_m3', 'Volumen de losa m3', 'decimal', [
                    'op' => 'multiply',
                    'args' => [
                        ['var' => 'area_m2'],
                        ['op' => 'divide', 'args' => [['var' => 'espesor_cm'], ['value' => 100]]],
                    ],
                ], ['area_m2', 'espesor_cm']),
            ],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function loans(): array
    {
        return [
            'entities' => [
                $this->entity('solicitud_prestamo', 'Solicitud de prestamo', 'Solicitudes de prestamo', 'finance', true, true),
                $this->entity('garantia_prestamo', 'Garantia', 'Garantias', 'finance', true, true),
            ],
            'fields' => [
                ...$this->fields('solicitud_prestamo', [
                    ['monto_solicitado', 'Monto solicitado', 'currency', true],
                    ['plazo_meses', 'Plazo meses', 'number', true],
                    ['interes_mensual', 'Interes mensual %', 'percentage', true],
                    ['estado_credito', 'Estado credito', 'select', true, ['solicitado', 'evaluado', 'aprobado', 'rechazado', 'desembolsado', 'en_mora', 'cerrado']],
                ], 'Prestamo', true),
                ...$this->fields('garantia_prestamo', [
                    ['descripcion_garantia', 'Descripcion garantia', 'text', true],
                    ['valor_estimado', 'Valor estimado', 'currency', true],
                    ['porcentaje_cobertura', 'Porcentaje cobertura', 'percentage', true],
                    ['foto_garantia', 'Foto garantia', 'image', false],
                ], 'Garantia', true),
            ],
            'relationships' => [
                $this->relationship('prestamo_garantias', 'Prestamo tiene garantias', 'solicitud_prestamo', 'garantia_prestamo', true),
            ],
            'forms' => [
                $this->form('solicitud_prestamo', 'aprobar_prestamo', 'Aprobar prestamo', ['monto_solicitado', 'plazo_meses', 'interes_mensual', 'estado_credito'], 'Aprobar prestamo'),
            ],
            'workflows' => [
                $this->workflow('solicitud_prestamo', 'flujo_prestamo', 'Prestamo', 'solicitado', ['cerrado', 'rechazado'], [
                    ['solicitado', 'evaluado', 'Evaluar'],
                    ['evaluado', 'aprobado', 'Aprobar'],
                    ['evaluado', 'rechazado', 'Rechazar'],
                    ['aprobado', 'desembolsado', 'Desembolsar'],
                    ['desembolsado', 'en_mora', 'Marcar mora'],
                    ['desembolsado', 'cerrado', 'Cerrar'],
                ]),
            ],
            'formulas' => [
                $this->formula('solicitud_prestamo', 'garantia_minima', 'Garantia minima requerida', 'currency', [
                    'op' => 'percentage',
                    'args' => [['var' => 'monto_solicitado'], ['var' => 'porcentaje_garantia']],
                ], ['monto_solicitado', 'porcentaje_garantia']),
            ],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function reservationBusiness(string $entityCode, string $label, string $plural): array
    {
        return [
            'entities' => [
                $this->entity($entityCode, $label, $plural, 'reservations', true),
            ],
            'fields' => [
                ...$this->fields($entityCode, [
                    ['capacidad', 'Capacidad', 'number', false],
                    ['ubicacion', 'Ubicacion', 'text', false],
                    ['estado_recurso', 'Estado recurso', 'select', true, ['disponible', 'reservado', 'mantenimiento', 'inactivo']],
                ], $label),
            ],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function transportLogistics(): array
    {
        return [
            'entities' => [
                $this->entity('vehiculo_transporte', 'Vehiculo transporte', 'Vehiculos transporte', 'services', true),
                $this->entity('ruta_servicio', 'Ruta de servicio', 'Rutas de servicio', 'services', true),
            ],
            'fields' => [
                ...$this->fields('vehiculo_transporte', [
                    ['placa', 'Placa', 'text', true],
                    ['tipo_transporte', 'Tipo transporte', 'select', true, ['moto', 'auto', 'camioneta', 'camion']],
                    ['capacidad_kg', 'Capacidad kg', 'decimal', false],
                ], 'Vehiculo'),
                ...$this->fields('ruta_servicio', [
                    ['origen', 'Origen', 'text', true],
                    ['destino', 'Destino', 'text', true],
                    ['distancia_km', 'Distancia km', 'decimal', false],
                    ['tiempo_minutos', 'Tiempo minutos', 'number', false],
                ], 'Ruta'),
            ],
            'formulas' => [
                $this->formula('ruta_servicio', 'costo_ruta', 'Costo por ruta', 'currency', [
                    'op' => 'add',
                    'args' => [
                        ['op' => 'multiply', 'args' => [['var' => 'distancia_km'], ['var' => 'tarifa_km']]],
                        ['var' => 'cargo_base'],
                    ],
                ], ['distancia_km', 'tarifa_km', 'cargo_base']),
            ],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function education(): array
    {
        return [
            'entities' => [
                $this->entity('curso', 'Curso', 'Cursos', 'services', true),
                $this->entity('inscripcion', 'Inscripcion', 'Inscripciones', 'services', true),
            ],
            'fields' => [
                ...$this->fields('curso', [
                    ['nombre_curso', 'Nombre del curso', 'text', true],
                    ['docente', 'Docente', 'text', false],
                    ['horario', 'Horario', 'text', false],
                ], 'Curso'),
                ...$this->fields('inscripcion', [
                    ['estudiante', 'Estudiante', 'text', true],
                    ['estado_inscripcion', 'Estado inscripcion', 'select', true, ['preinscrito', 'inscrito', 'finalizado', 'retirado']],
                ], 'Inscripcion'),
            ],
            'relationships' => [
                $this->relationship('curso_inscripciones', 'Curso tiene inscripciones', 'curso', 'inscripcion'),
            ],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function healthBase(string $businessType, string $patientLabel): array
    {
        return [
            'metadata' => ['business_type' => $businessType],
            'entities' => [
                $this->entity('paciente', $patientLabel, 'Pacientes', 'customers', true, true),
            ],
            'fields' => [
                ...$this->fields('paciente', [
                    ['ci', 'CI', 'identity_document', true],
                    ['telefono', 'Telefono', 'phone', true],
                    ['alergias', 'Alergias', 'text', false],
                    ['antecedentes', 'Antecedentes', 'text', false],
                    ['motivo_consulta', 'Motivo de consulta', 'text', false],
                    ['diagnostico', 'Diagnostico', 'text', false],
                ], 'Ficha medica', true),
            ],
        ];
    }

    /**
     * @param array<string, mixed> ...$blueprints
     * @return array<string, mixed>
     */
    private function mergeBlueprints(array ...$blueprints): array
    {
        $merged = ['entities' => [], 'fields' => [], 'relationships' => [], 'forms' => [], 'workflows' => [], 'formulas' => [], 'metadata' => []];

        foreach ($blueprints as $blueprint) {
            foreach (['entities', 'fields', 'relationships', 'forms', 'workflows', 'formulas'] as $key) {
                $merged[$key] = [...$merged[$key], ...($blueprint[$key] ?? [])];
            }

            $merged['metadata'] = array_replace_recursive($merged['metadata'], $blueprint['metadata'] ?? []);
        }

        return $merged;
    }

    /**
     * @return array<string, mixed>
     */
    private function entity(string $code, string $label, string $pluralLabel, string $module, bool $reportable = false, bool $sensitive = false): array
    {
        return [
            'code' => $code,
            'label' => $label,
            'plural_label' => $pluralLabel,
            'module' => $module,
            'mode' => 'optional',
            'is_visible' => true,
            'is_editable' => true,
            'is_required' => false,
            'is_exportable' => $reportable,
            'is_reportable' => $reportable,
            'is_auditable' => true,
            'is_sensitive' => $sensitive,
            'retention_policy' => $sensitive ? 'legal_hold' : 'manual',
            'settings' => ['source' => 'specialized_blueprint'],
            'is_active' => true,
        ];
    }

    /**
     * @param array<int, array{0:string,1:string,2:string,3?:bool,4?:array<int, string>}> $fields
     * @return array<int, array<string, mixed>>
     */
    private function fields(string $entityType, array $fields, string $group, bool $sensitive = false): array
    {
        return collect($fields)
            ->values()
            ->map(fn (array $field, int $index): array => [
                'entity_type' => $entityType,
                'code' => $field[0],
                'label' => $field[1],
                'type' => $field[2],
                'group' => $group,
                'options' => $field[4] ?? null,
                'is_required' => $field[3] ?? false,
                'visible_in_forms' => true,
                'visible_in_table' => $index < 4,
                'visible_in_documents' => true,
                'visible_in_reports' => true,
                'is_exportable' => ! $sensitive,
                'is_auditable' => true,
                'is_sensitive' => $sensitive,
                'is_encrypted' => $sensitive,
                'is_read_only' => false,
                'sort_order' => ($index + 1) * 10,
                'is_active' => true,
            ])
            ->all();
    }

    /**
     * @return array<string, mixed>
     */
    private function relationship(string $code, string $label, string $source, string $target, bool $required = false): array
    {
        return [
            'code' => $code,
            'label' => $label,
            'source_entity_type' => $source,
            'target_entity_type' => $target,
            'type' => 'one_to_many',
            'allows_multiple' => true,
            'is_required' => $required,
            'cascade_behavior' => 'restrict',
            'is_active' => true,
        ];
    }

    /**
     * @param array<int, string> $fields
     * @return array<string, mixed>
     */
    private function form(string $entityType, string $code, string $name, array $fields, string $submitLabel): array
    {
        return [
            'entity_type' => $entityType,
            'code' => $code,
            'name' => $name,
            'surface' => 'form',
            'submit_label' => $submitLabel,
            'fields' => $fields,
            'is_active' => true,
        ];
    }

    /**
     * @param array<int, array{0:string,1:string,2:string}> $transitions
     * @param array<int, string> $finalStates
     * @return array<string, mixed>
     */
    private function workflow(string $entityType, string $code, string $name, string $initialState, array $finalStates, array $transitions): array
    {
        return [
            'entity_type' => $entityType,
            'code' => $code,
            'name' => $name,
            'initial_state_code' => $initialState,
            'final_state_codes' => $finalStates,
            'transitions' => collect($transitions)
                ->map(fn (array $transition): array => [
                    'from_state_code' => $transition[0],
                    'to_state_code' => $transition[1],
                    'label' => $transition[2],
                    'requires_reason' => false,
                    'is_reversible' => false,
                    'is_active' => true,
                ])
                ->all(),
            'is_default' => true,
            'is_active' => true,
        ];
    }

    /**
     * @param array<string, mixed> $expression
     * @param array<int, string> $variables
     * @return array<string, mixed>
     */
    private function formula(string $entityType, string $code, string $name, string $resultType, array $expression, array $variables): array
    {
        return [
            'entity_type' => $entityType,
            'code' => $code,
            'name' => $name,
            'result_type' => $resultType,
            'expression' => $expression,
            'variables' => collect($variables)->map(fn (string $variable): array => ['code' => $variable])->all(),
            'precision' => 2,
            'is_active' => true,
        ];
    }
}
