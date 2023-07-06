<?php
/**
 * This file is part of Modelo130 plugin for FacturaScripts
 * Copyright (C) 2021-2023 Carlos Garcia Gomez            <carlos@facturascripts.com>
 *                         Jeronimo Pedro Sánchez Manzano <socger@gmail.com>
 * This program is free software: you can redistribute it and/or modify
 * it under the terms of the GNU Lesser General Public License as
 * published by the Free Software Foundation, either version 3 of the
 * License, or (at your option) any later version.
 *
 * This program is distributed in the hope that it will be useful,
 * but WITHOUT ANY WARRANTY; without even the implied warranty of
 * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE. See the
 * GNU Lesser General Public License for more details.
 *
 * You should have received a copy of the GNU Lesser General Public License
 * along with this program. If not, see <http://www.gnu.org/licenses/>.
 */

namespace FacturaScripts\Plugins\Modelo130\Controller;

use FacturaScripts\Core\Base\Controller;
use FacturaScripts\Core\Base\DataBase\DataBaseWhere;
use FacturaScripts\Core\DataSrc\Ejercicios;
use FacturaScripts\Dinamic\Model\Asiento;
use FacturaScripts\Dinamic\Model\Ejercicio;
use FacturaScripts\Dinamic\Model\FacturaCliente;
use FacturaScripts\Dinamic\Model\FacturaProveedor;
use FacturaScripts\Dinamic\Model\Partida;

/**
 * Description of Modelo130
 *
 * @author Carlos Garcia Gomez            <carlos@facturascripts.com>
 * @author Jerónimo Pedro Sánchez Manzano <socger@gmail.com>
 * @author Javier Martín González         <javier@javiermarting.es>
 */
class Modelo130 extends Controller
{
    /** @var Partida[] */
    public $accountingEntries = [];

    /** @var float */
    public $afterdeduct = 0.0;

    /** @var string */
    public $codejercicio;

    /** @var FacturaCliente[] */
    public $customerInvoices = [];

    /** @var string */
    public $dateEnd;

    /** @var string */
    public $dateStart;

    /** @var int */
    protected $idempresa;

    /** @var string */
    public $period = 'T1';

    /** @var float */
    public $positivosTrimestres = 0.0;

    /** @var float */
    public $result = 0.0;

    /** @var float */
    public $segSocial = 0.0;

    /** @var FacturaProveedor[] */
    public $supplierInvoices = [];

    /** @var float */
    public $taxbase = 0.0;

    /** @var float */
    public $taxbaseGastos = 0.0;

    /** @var float */
    public $taxbaseIngresos = 0.0;

    /** @var float */
    public $taxbaseRetenciones = 0.0;

    /** @var float */
    public $todeduct = 20.0;

    /**
     * @param int|null $idempresa
     * @return Ejercicio[]
     */
    public function getAllExercises(?int $idempresa): array
    {
        if (empty($idempresa)) {
            return Ejercicios::all();
        }

        $list = [];
        foreach (Ejercicios::all() as $exercise) {
            if ($exercise->idempresa === $idempresa) {
                $list[] = $exercise;
            }
        }
        return $list;
    }

    public function getPageData(): array
    {
        $data = parent::getPageData();
        $data['menu'] = 'reports';
        $data['title'] = 'model-130';
        $data['icon'] = 'fas fa-book';
        return $data;
    }

    public function getPeriodsForComboBoxHtml(): array
    {
        return [
            'T1' => 'first-trimester',
            'T2' => 'second-trimester',
            'T3' => 'third-trimester',
            'T4' => 'fourth-trimester'
        ];
    }

    public function privateCore(&$response, $user, $permissions)
    {
        parent::privateCore($response, $user, $permissions);

        $this->loadDates();
        $this->loadInvoices();
        $this->loadAsientos();
        $this->loadResults();
    }

    // Traemos del codejercicio y period elegido idempresa, dateStart y dateEnd
    protected function loadDates(): void
    {
        // Preparamos fecha de Inicio y Fin, según Ejercicio/Periodo introducido en la vista
        $this->codejercicio = $this->request->request->get('codejercicio', '');
        $this->period = $this->request->request->get('period', $this->period);

        $exercise = new Ejercicio();
        $exercise->loadFromCode($this->codejercicio);
        $this->idempresa = $exercise->idempresa;

        // Cargamos las variables dateStart y dateEnd con los valores de inicio y fin del codeejercicio elegido
        switch ($this->period) {
            case 'T1':
                $this->dateStart = date('01-01-Y', strtotime($exercise->fechainicio));
                $this->dateEnd = date('31-03-Y', strtotime($exercise->fechainicio));
                break;

            case 'T2':
                $this->dateStart = date('01-01-Y', strtotime($exercise->fechainicio));
                $this->dateEnd = date('30-06-Y', strtotime($exercise->fechainicio));
                break;

            case 'T3':
                $this->dateStart = date('01-01-Y', strtotime($exercise->fechainicio));
                $this->dateEnd = date('30-09-Y', strtotime($exercise->fechainicio));
                break;

            default:
                $this->dateStart = date('01-01-Y', strtotime($exercise->fechainicio));
                $this->dateEnd = date('31-12-Y', strtotime($exercise->fechainicio));
                break;
        }
    }

    protected function loadInvoices(): void
    {
        $ftrasProveedores = new FacturaProveedor();
        $ftrasClientes = new FacturaCliente();

        $whereFtrasProveedores = [
            // Para buscar en el margen de fechas del periodo
            new DataBaseWhere('fecha', date('Y-m-d', strtotime($this->dateStart)), '>='),
            new DataBaseWhere('fecha', date('Y-m-d', strtotime($this->dateEnd)), '<='),

            // Para buscar ftras solo de la empresa/Ejercicio elegido
            new DataBaseWhere('idempresa', $this->idempresa),
        ];

        $whereFtrasClientes = [
            // Para buscar en el margen de fechas del periodo
            new DataBaseWhere('fecha', date('Y-m-d', strtotime($this->dateStart)), '>='),
            new DataBaseWhere('fecha', date('Y-m-d', strtotime($this->dateEnd)), '<='),

            // Para buscar ftras solo de la empresa/Ejercicio elegido
            new DataBaseWhere('idempresa', $this->idempresa),
        ];

        // Preparamos el orderBy de como vamos a traer las facturas (fecha + numero ftra)
        $order = ['fecha' => 'ASC', 'numero' => 'ASC'];

        // Cargamos primero las facturas de proveedores
        $this->supplierInvoices = $ftrasProveedores->all($whereFtrasProveedores, $order, 0, 0);

        // Cargamos ahora las facturas de clientes
        $this->customerInvoices = $ftrasClientes->all($whereFtrasClientes, $order, 0, 0);
    }

    protected function loadAsientos(): void
    {
        // 6420000000 Seguridad social, 4730000000 IRPF
        $codsubs = ['6420000000', '4730000000'];

        // Buscar asientos entre las fechas de los tipos anteriores, la partida 473
        // también obtiene las facturas con retención aplicada que se mostarán en asientos
        $sql = 'SELECT * FROM ' . Partida::tableName() . ' as p'
            . ' LEFT JOIN ' . Asiento::tableName() . ' as a ON p.idasiento = a.idasiento'
            . ' WHERE a.idempresa = ' . $this->dataBase->var2str($this->idempresa)
            . ' AND a.fecha BETWEEN ' . $this->dataBase->var2str(date('Y-m-d', strtotime($this->dateStart)))
            . ' AND ' . $this->dataBase->var2str(date('Y-m-d', strtotime($this->dateEnd)))
            . ' AND p.codsubcuenta IN (' . implode(',', $codsubs) . ')'
            . ' ORDER BY numero ASC';

        foreach ($this->dataBase->select($sql) as $row) {
            $this->accountingEntries[] = new Partida($row);
        }
    }

    protected function loadResults(): void
    {
        foreach ($this->customerInvoices as $invoice) {
            $this->taxbaseIngresos += $invoice->neto;
            $this->taxbaseRetenciones += $invoice->totalirpf;
        }

        foreach ($this->supplierInvoices as $invoice) {
            $this->taxbaseGastos += $invoice->neto;
        }

        foreach ($this->accountingEntries as $asiento) {
            if ($asiento->codsubcuenta == '6420000000') {
                $this->segSocial += $asiento->debe;
            } else if ($asiento->codsubcuenta == '4730000000') {
                $this->positivosTrimestres += $asiento->debe;
            }
        }

        // La seguridad social se cuenta como un gasto deducible
        $this->taxbaseGastos += $this->segSocial;

        // La partida 473 incluye tanto trimestres anteriores como las retenciones de facturas
        $this->positivosTrimestres = round($this->positivosTrimestres - $this->taxbaseRetenciones, 2);

        // Primero calculamos rendimiento neto: ingresos(ftras ventas) - gastos (ftras compras/gastos + SS) acumulado anual
        // El cálculo nos dará un número negativo o positivo que serán las pérdidas o los beneficios respectivamente
        // El importe a deducir debe ser del 20% según modelo 130 o superior si se desea ingresar un IRPF superior
        // Después se deben restar las retenciones aplicadas en las facturas de venta ya que eso lo ingresa el cliente en tu nombre
        // Igualmente como es el acumulado del año, se deben restar también los trimestrales ya pagados y registrado el asiento
        // Si sale número negativo, el importe a ingresar este trimestra será 0
        // Si sale positivo, dicha cantidad es la que corresponde ingresar y rellenando las casillas de Hacienda de acuerdo a los campos mostrados
        // Habría que ver la posibilidad de añadir un botón para agregar el asiento de pago de cara a siguientes trimestres (el plugin no lo hace)
        // Actualmente los asientos de Seguridad Social (642) y de trimestres anteriores (473) se mete a mano (una forma rápida es con el plugin Asientos Predefinidos)
        // En este link se explica como calcular el modelo 130
        // https://tuspapelesautonomos.es/modelo-130-como-se-calcula-descubrelo-facil-con-ejemplos/

        $this->taxbase = round($this->taxbaseIngresos - $this->taxbaseGastos, 2);

        $this->todeduct = (float)$this->request->request->get('todeduct', $this->todeduct);

        $this->afterdeduct = round(($this->taxbase * $this->todeduct) / 100, 2);

        $this->result = round($this->afterdeduct - $this->taxbaseRetenciones - $this->positivosTrimestres, 2);

        if ($this->result < 0) {
            $this->result = 0;
        }
    }
}
