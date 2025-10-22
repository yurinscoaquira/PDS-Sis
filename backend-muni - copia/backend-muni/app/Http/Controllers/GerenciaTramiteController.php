<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use App\Models\Expediente;
use App\Models\Gerencia;
use App\Models\User;

class GerenciaTramiteController extends Controller
{
    public function __construct()
    {
        $this->middleware('auth');
    }

    /**
     * Mostrar trámites asignados a mi gerencia
     */
    public function misAsignados()
    {
        $user = auth()->user();
        
        // Obtener la gerencia del usuario
        $gerencia = $user->gerencia;
        
        if (!$gerencia) {
            return redirect()->route('dashboard')
                ->with('error', 'No tienes una gerencia asignada');
        }
        
        // Obtener expedientes asignados al usuario actual en el workflow
        $expedientes = Expediente::with([
            'tipoTramite',
            'gerencia',
            'currentStep',
            'workflow',
            'documentos'
        ])
        ->whereHas('workflowProgress', function($query) use ($user) {
            $query->where('asignado_a', $user->id)
                  ->whereIn('estado', ['pendiente', 'en_proceso']);
        })
        ->whereIn('estado', ['pendiente', 'en_proceso', 'en_revision', 'observado'])
        ->orderBy('created_at', 'desc')
        ->paginate(15);
        
        return view('gerencia.tramites.mis-asignados', compact('expedientes', 'gerencia'));
    }

    /**
     * Ver detalles de un trámite asignado
     */
    public function show($id)
    {
        $user = auth()->user();
        $gerencia = $user->gerencia;
        
        if (!$gerencia) {
            abort(403, 'No tienes una gerencia asignada');
        }
        
        $expediente = Expediente::with([
            'tipoTramite.workflow.steps',
            'gerencia',
            'documentos',
            'historial.usuario',
            'workflowProgress',
            'currentStep',
            'usuarioRegistro'
        ])
        ->where('gerencia_id', $gerencia->id)
        ->findOrFail($id);
        
        return view('gerencia.tramites.show', compact('expediente'));
    }

    /**
     * Avanzar el trámite al siguiente paso
     */
    public function avanzar(Request $request, $id)
    {
        $validated = $request->validate([
            'observaciones' => 'nullable|string|max:1000',
            'siguiente_paso_id' => 'required|string'
        ]);
        
        $user = auth()->user();
        $gerencia = $user->gerencia;
        
        if (!$gerencia) {
            return redirect()->back()->with('error', 'No tienes una gerencia asignada');
        }
        
        $expediente = Expediente::where('gerencia_id', $gerencia->id)
            ->findOrFail($id);
        
        // Guardar paso anterior para el historial
        $pasoAnterior = $expediente->currentStep ? $expediente->currentStep->nombre : 'Inicio';
        
        // Si es finalización
        if ($validated['siguiente_paso_id'] === 'finalizado') {
            $expediente->estado = 'finalizado';
            $expediente->current_step_id = null;
            $expediente->save();
            
            // Registrar finalización en historial
            \App\Models\HistorialExpediente::create([
                'expediente_id' => $expediente->id,
                'accion' => 'Trámite Finalizado',
                'descripcion' => $validated['observaciones'] ?? 'Trámite completado exitosamente',
                'usuario_id' => $user->id,
            ]);
            
            return redirect()->route('gerencia.tramites.show', $expediente->id)
                ->with('success', '¡Trámite finalizado exitosamente!');
        }
        
        // Validar que el siguiente paso existe
        $siguientePaso = \App\Models\WorkflowStep::findOrFail($validated['siguiente_paso_id']);
        
        // Actualizar el paso actual
        $expediente->current_step_id = $siguientePaso->id;
        
        // Actualizar estado si es necesario
        if ($expediente->estado === 'pendiente') {
            $expediente->estado = 'en_proceso';
        }
        
        $expediente->save();
        
        // Registrar en historial
        \App\Models\HistorialExpediente::create([
            'expediente_id' => $expediente->id,
            'accion' => 'Avance de Flujo',
            'descripcion' => "Avanzó de '{$pasoAnterior}' a '{$siguientePaso->nombre}'. " . ($validated['observaciones'] ?? ''),
            'usuario_id' => $user->id,
        ]);
        
        return redirect()->route('gerencia.tramites.show', $expediente->id)
            ->with('success', "Trámite avanzado correctamente a: {$siguientePaso->nombre}");
    }

    /**
     * Aprobar el trámite
     */
    public function aprobar(Request $request, $id)
    {
        $validated = $request->validate([
            'comentarios' => 'nullable|string|max:1000',
            'documentos' => 'nullable|array',
            'documentos.*' => 'file|max:10240' // 10MB por archivo
        ]);

        $user = auth()->user();
        $gerencia = $user->gerencia;

        if (!$gerencia) {
            return redirect()->back()->with('error', 'No tienes una gerencia asignada');
        }

        $expediente = Expediente::where('gerencia_id', $gerencia->id)
            ->findOrFail($id);

        // Verificar que el expediente esté en proceso o pendiente
        if (!in_array($expediente->estado, ['pendiente', 'en_proceso', 'observado', 'en_revision'])) {
            return redirect()->back()
                ->with('error', 'Este trámite no puede ser aprobado en su estado actual');
        }

        // Obtener el progreso del workflow actual
        $progresoActual = $expediente->workflowProgress()
            ->where('workflow_step_id', $expediente->current_step_id)
            ->whereIn('estado', ['pendiente', 'en_proceso'])
            ->first();

        $mensajeExito = '¡Paso aprobado exitosamente!';
        $estadoAnterior = $expediente->estado;

        if ($progresoActual) {
            // Aprobar el paso actual (esto automáticamente avanza al siguiente)
            $progresoActual->aprobar(
                $validated['comentarios'] ?? 'Aprobado por ' . $user->name,
                null // documentos adjuntos si se implementa
            );

            // Recargar el expediente para obtener el estado actualizado
            $expediente->refresh();

            // Verificar si hay siguiente etapa
            $siguienteEtapa = $progresoActual->workflowStep->siguienteEtapa();
            
            if ($siguienteEtapa) {
                // Hay más etapas, mantener en proceso
                if ($expediente->estado !== 'en_proceso') {
                    $expediente->estado = 'en_proceso';
                    $expediente->save();
                }
                $mensajeExito = "¡Paso aprobado! El trámite avanzó a: {$siguienteEtapa->nombre}";
            } else {
                // Era la última etapa, marcar como aprobado/finalizado
                $expediente->estado = 'aprobado';
                $expediente->save();
                $mensajeExito = '¡Trámite completado y aprobado exitosamente!';
            }
        } else {
            // No hay progreso activo, aprobar directamente el expediente
            $expediente->estado = 'aprobado';
            $expediente->save();
            $mensajeExito = '¡Trámite aprobado exitosamente!';
        }

        // Registrar en historial
        \App\Models\HistorialExpediente::create([
            'expediente_id' => $expediente->id,
            'accion' => 'Paso Aprobado',
            'descripcion' => 'Paso aprobado por ' . $user->name . '. ' . ($validated['comentarios'] ?? ''),
            'usuario_id' => $user->id,
            'estado_anterior' => $estadoAnterior,
            'estado_nuevo' => $expediente->estado
        ]);

        // Notificar al ciudadano (TODO: implementar notificación)

        return redirect()->route('gerencia.tramites.show', $expediente->id)
            ->with('success', $mensajeExito);
    }

    /**
     * Rechazar el trámite
     */
    public function rechazar(Request $request, $id)
    {
        $validated = $request->validate([
            'motivo' => 'required|string|max:1000',
            'comentarios' => 'nullable|string|max:1000'
        ]);

        $user = auth()->user();
        $gerencia = $user->gerencia;

        if (!$gerencia) {
            return redirect()->back()->with('error', 'No tienes una gerencia asignada');
        }

        $expediente = Expediente::where('gerencia_id', $gerencia->id)
            ->findOrFail($id);

        // Verificar que el expediente esté en proceso o pendiente
        if (!in_array($expediente->estado, ['pendiente', 'en_proceso', 'observado', 'en_revision'])) {
            return redirect()->back()
                ->with('error', 'Este trámite no puede ser rechazado en su estado actual');
        }

        // Obtener el progreso del workflow actual
        $progresoActual = $expediente->workflowProgress()
            ->where('workflow_step_id', $expediente->current_step_id)
            ->whereIn('estado', ['pendiente', 'en_proceso'])
            ->first();

        if ($progresoActual) {
            // Rechazar el paso actual
            $progresoActual->rechazar(
                $validated['motivo'],
                $validated['comentarios'] ?? null
            );
        }

        // Actualizar estado del expediente
        $expediente->estado = 'rechazado';
        $expediente->save();

        // Registrar en historial
        \App\Models\HistorialExpediente::create([
            'expediente_id' => $expediente->id,
            'accion' => 'Trámite Rechazado',
            'descripcion' => 'Trámite rechazado por ' . $user->name . '. Motivo: ' . $validated['motivo'],
            'usuario_id' => $user->id,
            'estado_anterior' => $expediente->estado,
            'estado_nuevo' => 'rechazado'
        ]);

        // Notificar al ciudadano (TODO: implementar notificación)

        return redirect()->route('gerencia.tramites.mis-asignados')
            ->with('success', 'Trámite rechazado. El ciudadano será notificado.');
    }

    /**
     * Agregar observación al trámite
     */
    public function agregarObservacion(Request $request, $id)
    {
        $validated = $request->validate([
            'observacion' => 'required|string|max:1000'
        ]);

        $user = auth()->user();
        $gerencia = $user->gerencia;

        if (!$gerencia) {
            return redirect()->back()->with('error', 'No tienes una gerencia asignada');
        }

        $expediente = Expediente::where('gerencia_id', $gerencia->id)
            ->findOrFail($id);

        // Actualizar estado si es necesario
        if ($expediente->estado === 'en_proceso' || $expediente->estado === 'pendiente') {
            $expediente->estado = 'observado';
            $expediente->save();
        }

        // Registrar observación en historial
        \App\Models\HistorialExpediente::create([
            'expediente_id' => $expediente->id,
            'accion' => 'Observación Agregada',
            'descripcion' => $validated['observacion'],
            'usuario_id' => $user->id,
        ]);

        return redirect()->back()
            ->with('success', 'Observación agregada correctamente');
    }
}
